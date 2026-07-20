<?php
// C:\laragon\www\control-finanzas\backend\api\insights.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$workspace = get_active_workspace();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // 1. Obtener Cuentas y Saldo Líquido Total por workspace
        $stmtAccounts = $db->prepare("SELECT SUM(balance) as total_liquid FROM accounts WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND type IN ('efectivo', 'banco', 'ahorro')");
        $stmtAccounts->execute([$userId, $workspace]);
        $liquidData = $stmtAccounts->fetch();
        $totalLiquidBalance = floatval($liquidData['total_liquid'] ?? 0);

        // 2. Transacciones de los últimos 90 días por workspace
        $stmt3Months = $db->prepare("
            SELECT type, amount, category_id, description, date 
            FROM transactions 
            WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND date >= DATE_SUB(CURRENT_DATE(), INTERVAL 90 DAY)
        ");
        $stmt3Months->execute([$userId, $workspace]);
        $txs90Days = $stmt3Months->fetchAll();

        // 3. Transacciones del Mes Actual por workspace
        $stmtCurrentMonth = $db->prepare("
            SELECT t.type, t.amount, t.category_id, t.description, t.date, c.name as category_name
            FROM transactions t
            LEFT JOIN categories c ON t.category_id = c.id
            WHERE t.user_id = ? AND (t.workspace IS NULL OR t.workspace = ?) AND MONTH(t.date) = MONTH(CURRENT_DATE()) AND YEAR(t.date) = YEAR(CURRENT_DATE())
        ");
        $stmtCurrentMonth->execute([$userId, $workspace]);
        $txsCurrentMonth = $stmtCurrentMonth->fetchAll();

        // Calcular Totales del Mes Actual
        $currentMonthIncome = 0;
        $currentMonthExpense = 0;
        $currentMonthByCategory = [];

        foreach ($txsCurrentMonth as $tx) {
            $amt = floatval($tx['amount']);
            if ($tx['type'] === 'ingreso') {
                $currentMonthIncome += $amt;
            } elseif ($tx['type'] === 'egreso') {
                $currentMonthExpense += $amt;
                $catName = $tx['category_name'] ?: 'Sin Categoría';
                if (!isset($currentMonthByCategory[$catName])) {
                    $currentMonthByCategory[$catName] = 0;
                }
                $currentMonthByCategory[$catName] += $amt;
            }
        }

        // Calcular Promedio de Gastos Mensuales (últimos 3 meses o mes actual como fallback)
        $totalExpense90Days = 0;
        foreach ($txs90Days as $tx) {
            if ($tx['type'] === 'egreso') {
                $totalExpense90Days += floatval($tx['amount']);
            }
        }
        $avgMonthlyExpense = $totalExpense90Days > 0 ? ($totalExpense90Days / 3) : max(1, $currentMonthExpense);

        // --- CÁLCULO 1: AUTONOMÍA FINANCIERA (RUNWAY) ---
        $runwayMonths = $avgMonthlyExpense > 0 ? ($totalLiquidBalance / $avgMonthlyExpense) : 0;
        $runwayDays = round($runwayMonths * 30);

        // --- CÁLCULO 2: PRERICCIÓN DE CIERRE DE MES ---
        $currentDay = intval(date('j'));
        $daysInMonth = intval(date('t'));
        $paceFactor = $currentDay > 0 ? ($daysInMonth / $currentDay) : 1;

        $projectedIncome = $currentMonthIncome * $paceFactor;
        $projectedExpense = $currentMonthExpense * $paceFactor;
        $projectedSavings = $projectedIncome - $projectedExpense;

        // --- CÁLCULO 3: DETECTOR DE SUSCRIPCIONES Y GASTOS RECURRENTES ---
        $recurringKeywords = ['netflix', 'spotify', 'gym', 'gimnasio', 'icloud', 'google', 'amazon', 'prime', 'disney', 'hbo', 'youtube', 'internet', 'claro', 'movistar', 'tigo', 'seguro', 'suscripcion', 'membresia', 'apple'];
        $subscriptions = [];
        $totalMonthlySubscriptions = 0;

        $descriptionGroup = [];
        foreach ($txsCurrentMonth as $tx) {
            if ($tx['type'] === 'egreso') {
                $descLower = mb_strtolower($tx['description']);
                $isSubscription = false;
                foreach ($recurringKeywords as $kw) {
                    if (strpos($descLower, $kw) !== false) {
                        $isSubscription = true;
                        break;
                    }
                }
                if ($isSubscription) {
                    $key = trim($tx['description']);
                    if (!isset($descriptionGroup[$key])) {
                        $descriptionGroup[$key] = ['name' => $tx['description'], 'amount' => floatval($tx['amount']), 'category' => $tx['category_name']];
                    }
                }
            }
        }

        foreach ($descriptionGroup as $sub) {
            $subscriptions[] = $sub;
            $totalMonthlySubscriptions += $sub['amount'];
        }
        $totalAnnualSubscriptions = $totalMonthlySubscriptions * 12;

        // --- CÁLCULO 4: ALERTAS DE DESVÍOS Y ANOMALÍAS DE GASTO ---
        // Obtener promedio histórico por categoría en los últimos 90 días
        $stmtCatAvg = $db->prepare("
            SELECT c.name as category_name, SUM(t.amount) / 3 as avg_amount
            FROM transactions t
            JOIN categories c ON t.category_id = c.id
            WHERE t.user_id = ? AND t.type = 'egreso' AND t.date >= DATE_SUB(CURRENT_DATE(), INTERVAL 90 DAY)
            GROUP BY c.id
        ");
        $stmtCatAvg->execute([$userId]);
        $historicalAvgs = $stmtCatAvg->fetchAll(PDO::FETCH_KEY_PAIR);

        $anomalies = [];
        foreach ($currentMonthByCategory as $catName => $currentAmt) {
            $histAvg = floatval($historicalAvgs[$catName] ?? 0);
            if ($histAvg > 0 && $currentAmt > ($histAvg * 1.25)) { // 25% más alto
                $increasePct = round((($currentAmt - $histAvg) / $histAvg) * 100);
                $anomalies[] = [
                    'category' => $catName,
                    'current_amount' => $currentAmt,
                    'avg_amount' => round($histAvg, 2),
                    'increase_pct' => $increasePct
                ];
            }
        }

        // --- CÁLCULO 5: SCORE DE SALUD FINANCIERA (0 A 100) ---
        $scoreSavings = 0;
        if ($currentMonthIncome > 0) {
            $savingsRate = ($currentMonthIncome - $currentMonthExpense) / $currentMonthIncome;
            if ($savingsRate >= 0.20) $scoreSavings = 40; // 20% o más ahorro = 40 pts
            elseif ($savingsRate >= 0.10) $scoreSavings = 30; // 10% ahorro = 30 pts
            elseif ($savingsRate > 0) $scoreSavings = 20;
            else $scoreSavings = 5;
        } else {
            $scoreSavings = 20; // Default si no hay registros aún
        }

        $scoreRunway = 0;
        if ($runwayMonths >= 6) $scoreRunway = 30;
        elseif ($runwayMonths >= 3) $scoreRunway = 25;
        elseif ($runwayMonths >= 1) $scoreRunway = 15;
        else $scoreRunway = 5;

        // Presupuestos cumplidos
        $stmtBudgets = $db->prepare("
            SELECT b.amount as limit_amt, SUM(t.amount) as spent_amt
            FROM budgets b
            LEFT JOIN transactions t ON b.category_id = t.category_id AND t.user_id = b.user_id AND MONTH(t.date) = MONTH(CURRENT_DATE()) AND YEAR(t.date) = YEAR(CURRENT_DATE())
            WHERE b.user_id = ? AND b.month = MONTH(CURRENT_DATE()) AND b.year = YEAR(CURRENT_DATE())
            GROUP BY b.id
        ");
        $stmtBudgets->execute([$userId]);
        $budgets = $stmtBudgets->fetchAll();

        $scoreBudget = 20;
        if (!empty($budgets)) {
            $overBudgetCount = 0;
            foreach ($budgets as $b) {
                if (floatval($b['spent_amt']) > floatval($b['limit_amt'])) {
                    $overBudgetCount++;
                }
            }
            if ($overBudgetCount === 0) $scoreBudget = 20;
            elseif ($overBudgetCount === 1) $scoreBudget = 12;
            else $scoreBudget = 5;
        }

        // Préstamos / Deudas
        $stmtDeudas = $db->prepare("SELECT COUNT(*) as active_debts FROM loans WHERE user_id = ? AND type = 'por_pagar' AND status != 'finalizado'");
        $stmtDeudas->execute([$userId]);
        $activeDebts = intval($stmtDeudas->fetch()['active_debts'] ?? 0);
        $scoreDebt = $activeDebts === 0 ? 10 : 5;

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

        // Recomendación automática
        $recommendation = "Continúa manteniendo el control de tus finanzas.";
        if ($runwayMonths < 3) {
            $recommendation = "Tu fondo de emergencia es menor a 3 meses. Prioriza guardar el 10% de tus ingresos antes de realizar gastos no esenciales (Principio de Babilonia).";
        } elseif (!empty($anomalies)) {
            $recommendation = "Has gastado un " . $anomalies[0]['increase_pct'] . "% más de lo normal en " . $anomalies[0]['category'] . ". Revisa esa categoría para ajustar tu presupuesto.";
        } elseif ($totalMonthlySubscriptions > 0) {
            $recommendation = "Tus suscripciones suman " . number_format($totalMonthlySubscriptions, 0) . " al mes (" . number_format($totalAnnualSubscriptions, 0) . " al año). Evalúa si estás usando todas.";
        }

        echo json_encode([
            "health_score" => $totalHealthScore,
            "health_status" => $healthStatus,
            "health_color" => $healthColor,
            "recommendation" => $recommendation,
            "runway" => [
                "liquid_balance" => round($totalLiquidBalance, 2),
                "avg_monthly_expense" => round($avgMonthlyExpense, 2),
                "months" => round($runwayMonths, 1),
                "days" => $runwayDays
            ],
            "forecast" => [
                "current_income" => round($currentMonthIncome, 2),
                "current_expense" => round($currentMonthExpense, 2),
                "projected_income" => round($projectedIncome, 2),
                "projected_expense" => round($projectedExpense, 2),
                "projected_savings" => round($projectedSavings, 2),
                "days_passed" => $currentDay,
                "days_total" => $daysInMonth
            ],
            "subscriptions" => [
                "items" => $subscriptions,
                "monthly_total" => round($totalMonthlySubscriptions, 2),
                "annual_total" => round($totalAnnualSubscriptions, 2)
            ],
            "anomalies" => $anomalies
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al procesar la analítica financiera: " . $e->getMessage()]);
    }
    exit();
}
