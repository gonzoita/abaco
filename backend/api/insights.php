<?php
// backend/api/insights.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../lib/finance_analytics.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$workspace = get_active_workspace();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Todas las cifras salen de la misma librería que alimenta a la IA,
        // para que el tablero y el asesor nunca se contradigan. Antes este
        // endpoint tenía sus propias consultas y tres de ellas no filtraban
        // por espacio de trabajo (mezclaban lo personal con el negocio).
        $ctx = fa_build_context($db, $userId, $workspace, date('Y-m-d'));
        $current = $ctx['current'];
        $readiness = $ctx['readiness'];

        $totalLiquidBalance = $ctx['accounts']['liquid_total'];
        $avgMonthlyExpense = max(1, $ctx['expense_base']);
        $runwayMonths = $totalLiquidBalance / $avgMonthlyExpense;

        // --- DETECTOR DE SUSCRIPCIONES Y GASTOS RECURRENTES ---
        $recurringKeywords = ['netflix', 'spotify', 'gym', 'gimnasio', 'icloud', 'google', 'amazon', 'prime', 'disney', 'hbo', 'youtube', 'internet', 'claro', 'movistar', 'tigo', 'seguro', 'suscripcion', 'suscripción', 'membresia', 'membresía', 'apple'];
        [$monthStart, $monthEnd] = fa_month_range(intval(date('Y')), intval(date('n')));
        $tWsCond = get_workspace_sql_clause('t.workspace');
        $stmtMonth = $db->prepare("
            SELECT t.amount, t.description, c.name as category_name
            FROM transactions t
            LEFT JOIN categories c ON t.category_id = c.id
            WHERE t.user_id = ? AND {$tWsCond} AND t.type = 'egreso' AND t.date >= ? AND t.date <= ?
        ");
        $stmtMonth->execute([$userId, $monthStart, $monthEnd]);

        $subscriptions = [];
        foreach ($stmtMonth->fetchAll() as $tx) {
            $descLower = mb_strtolower($tx['description'] ?? '');
            foreach ($recurringKeywords as $kw) {
                if (strpos($descLower, $kw) !== false) {
                    $key = trim($tx['description']);
                    if (!isset($subscriptions[$key])) {
                        $subscriptions[$key] = ['name' => $tx['description'], 'amount' => floatval($tx['amount']), 'category' => $tx['category_name']];
                    }
                    break;
                }
            }
        }
        $subscriptions = array_values($subscriptions);
        $totalMonthlySubscriptions = array_sum(array_column($subscriptions, 'amount'));
        $totalAnnualSubscriptions = $totalMonthlySubscriptions * 12;

        // --- ALERTAS DE DESVÍOS: categorías 25% por encima de su promedio ---
        $anomalies = [];
        foreach ($ctx['categories'] as $cat) {
            if ($cat['avg_3m'] > 0 && $cat['spent'] > $cat['avg_3m'] * 1.25) {
                $anomalies[] = [
                    'category' => $cat['name'],
                    'current_amount' => $cat['spent'],
                    'avg_amount' => round($cat['avg_3m'], 2),
                    'increase_pct' => round(($cat['spent'] / $cat['avg_3m'] - 1) * 100)
                ];
            }
        }

        // --- SCORE DE SALUD FINANCIERA (0 A 100) ---
        $savingsRate = fa_savings_rate($current['income'], $current['expense']);
        if ($savingsRate === null) $scoreSavings = 20; // todavía no entra el ingreso del mes
        elseif ($savingsRate >= 0.20) $scoreSavings = 40;
        elseif ($savingsRate >= 0.10) $scoreSavings = 30;
        elseif ($savingsRate > 0) $scoreSavings = 20;
        else $scoreSavings = 5;

        if ($runwayMonths >= 6) $scoreRunway = 30;
        elseif ($runwayMonths >= 3) $scoreRunway = 25;
        elseif ($runwayMonths >= 1) $scoreRunway = 15;
        else $scoreRunway = 5;

        $overBudgetCount = $ctx['budget']['categories_over'];
        if ($overBudgetCount === 0) $scoreBudget = 20;
        elseif ($overBudgetCount === 1) $scoreBudget = 12;
        else $scoreBudget = 5;

        // Las deudas del usuario son sus cuentas de tipo tarjeta de crédito o
        // préstamo por pagar. La consulta anterior buscaba una columna
        // `loans.type` que no existe (la tabla loans es plata que el usuario
        // PRESTÓ), fallaba en silencio y por eso el puntaje siempre asumía
        // "sin deudas".
        $scoreDebt = empty($ctx['accounts']['debts']) ? 10 : 5;

        $totalHealthScore = min(100, $scoreSavings + $scoreRunway + $scoreBudget + $scoreDebt);

        $healthStatus = 'Excelente';
        $healthColor = '#30d158';
        if ($totalHealthScore < 50) {
            $healthStatus = 'En Riesgo';
            $healthColor = '#ff453a';
        } elseif ($totalHealthScore < 75) {
            $healthStatus = 'Saludable';
            $healthColor = '#ff9f0a';
        }

        $recommendation = "Continúa manteniendo el control de tus finanzas.";
        if ($readiness['stage'] === 'pagar_deuda_cara' || $readiness['stage'] === 'fondo_emergencia') {
            $recommendation = $readiness['headline'];
        } elseif (!empty($anomalies)) {
            $recommendation = "Has gastado un " . $anomalies[0]['increase_pct'] . "% más de lo normal en " . $anomalies[0]['category'] . ". Revisa esa categoría para ajustar tu presupuesto.";
        } elseif ($totalMonthlySubscriptions > 0) {
            $recommendation = "Tus suscripciones suman " . fa_money($totalMonthlySubscriptions) . " al mes (" . fa_money($totalAnnualSubscriptions) . " al año). Evalúa si estás usando todas.";
        } elseif ($readiness['stage'] === 'listo') {
            $recommendation = $readiness['headline'];
        }

        echo json_encode([
            "health_score" => $totalHealthScore,
            "health_status" => $healthStatus,
            "health_color" => $healthColor,
            "recommendation" => $recommendation,
            "runway" => [
                "liquid_balance" => round($totalLiquidBalance, 2),
                "avg_monthly_expense" => round($ctx['expense_base'], 2),
                "months" => round($runwayMonths, 1),
                "days" => round($runwayMonths * 30)
            ],
            "forecast" => [
                "current_income" => round($current['income'], 2),
                "current_expense" => round($current['expense'], 2),
                "projected_income" => round($ctx['projected_income'], 2),
                "projected_expense" => round($ctx['projected_expense'], 2),
                "projected_savings" => round($ctx['projected_income'] - $ctx['projected_expense'], 2),
                "days_passed" => $ctx['day'],
                "days_total" => $ctx['days_in_month']
            ],
            "subscriptions" => [
                "items" => $subscriptions,
                "monthly_total" => round($totalMonthlySubscriptions, 2),
                "annual_total" => round($totalAnnualSubscriptions, 2)
            ],
            "anomalies" => $anomalies,
            "debts" => [
                "total" => round($ctx['accounts']['debt_total'], 2),
                "count" => count($ctx['accounts']['debts'])
            ],
            "points" => $ctx['points'],
            "investment" => $readiness
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al procesar la analítica financiera: " . $e->getMessage()]);
    }
    exit();
}
