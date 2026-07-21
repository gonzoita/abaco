<?php
// C:\laragon\www\control-finanzas\backend\api\reports.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];

$workspace = get_active_workspace();

if ($method === 'GET') {
    try {
        $month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
        $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        $startDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : null;
        $endDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : null;

        $wsCond = get_workspace_sql_clause('workspace');
        $tWsCond = get_workspace_sql_clause('t.workspace');
        $bWsCond = get_workspace_sql_clause('b.workspace');

        // 1. Resumen mensual total de ingresos y egresos por workspace
        if ($startDate && $endDate) {
            $stmtSummary = $db->prepare("
                SELECT type, SUM(amount) as total 
                FROM transactions 
                WHERE user_id = ? AND {$wsCond} AND date >= ? AND date <= ?
                GROUP BY type
            ");
            $stmtSummary->execute([$userId, $startDate, $endDate]);
        } else {
            $stmtSummary = $db->prepare("
                SELECT type, SUM(amount) as total 
                FROM transactions 
                WHERE user_id = ? AND {$wsCond} AND MONTH(date) = ? AND YEAR(date) = ?
                GROUP BY type
            ");
            $stmtSummary->execute([$userId, $month, $year]);
        }
        $summaryRaw = $stmtSummary->fetchAll();

        $totals = ['ingresos' => 0.00, 'egresos' => 0.00, 'neto' => 0.00];
        foreach ($summaryRaw as $row) {
            if ($row['type'] === 'ingreso') {
                $totals['ingresos'] = floatval($row['total']);
            } elseif ($row['type'] === 'egreso') {
                $totals['egresos'] = floatval($row['total']);
            }
        }
        $totals['neto'] = $totals['ingresos'] - $totals['egresos'];

        // 2. Gastos agrupados por Categoría (para gráficos circulares)
        if ($startDate && $endDate) {
            $stmtCategories = $db->prepare("
                SELECT COALESCE(c.name, 'Sin Categoría') as name, 
                       COALESCE(c.color, '#64748b') as color, 
                       COALESCE(c.icon, 'fa-tag') as icon, 
                       SUM(t.amount) as total 
                FROM transactions t
                LEFT JOIN categories c ON t.category_id = c.id
                WHERE t.user_id = ? AND {$tWsCond} AND t.type = 'egreso' AND t.date >= ? AND t.date <= ?
                GROUP BY c.id, c.name, c.color, c.icon
                ORDER BY total DESC
            ");
            $stmtCategories->execute([$userId, $startDate, $endDate]);
        } else {
            $stmtCategories = $db->prepare("
                SELECT COALESCE(c.name, 'Sin Categoría') as name, 
                       COALESCE(c.color, '#64748b') as color, 
                       COALESCE(c.icon, 'fa-tag') as icon, 
                       SUM(t.amount) as total 
                FROM transactions t
                LEFT JOIN categories c ON t.category_id = c.id
                WHERE t.user_id = ? AND {$tWsCond} AND t.type = 'egreso' AND MONTH(t.date) = ? AND YEAR(t.date) = ?
                GROUP BY c.id, c.name, c.color, c.icon
                ORDER BY total DESC
            ");
            $stmtCategories->execute([$userId, $month, $year]);
        }
        $categoriesData = $stmtCategories->fetchAll();

        // Convertir montos a float
        foreach ($categoriesData as &$cat) {
            $cat['total'] = floatval($cat['total']);
        }

        // 3. Histórico diario de los últimos 30 días (para gráficos de tendencias)
        $stmtDaily = $db->prepare("
            SELECT date, type, SUM(amount) as total 
            FROM transactions 
            WHERE user_id = ? AND {$wsCond} AND date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
            GROUP BY date, type
            ORDER BY date ASC
        ");
        $stmtDaily->execute([$userId]);
        $dailyRaw = $stmtDaily->fetchAll();

        $dailyTrends = [];
        foreach ($dailyRaw as $row) {
            $date = $row['date'];
            if (!isset($dailyTrends[$date])) {
                $dailyTrends[$date] = ['date' => $date, 'ingresos' => 0.00, 'egresos' => 0.00];
            }
            if ($row['type'] === 'ingreso') {
                $dailyTrends[$date]['ingresos'] = floatval($row['total']);
            } elseif ($row['type'] === 'egreso') {
                $dailyTrends[$date]['egresos'] = floatval($row['total']);
            }
        }
        // Aplanar a un array ordenado
        $dailyTrends = array_values($dailyTrends);

        // 4. Progreso de objetivos de ahorro por workspace
        $stmtSavings = $db->prepare("
            SELECT name, target_amount, current_amount, target_date 
            FROM savings_goals 
            WHERE user_id = ? AND {$wsCond}
            ORDER BY created_at DESC LIMIT 5
        ");
        $stmtSavings->execute([$userId]);
        $savingsData = $stmtSavings->fetchAll();

        foreach ($savingsData as &$sav) {
            $sav['target_amount'] = floatval($sav['target_amount']);
            $sav['current_amount'] = floatval($sav['current_amount']);
            $sav['percentage'] = $sav['target_amount'] > 0 ? round(($sav['current_amount'] / $sav['target_amount']) * 100, 2) : 0;
        }

        // 5. Comparativa de Presupuestos vs Gastos Reales por workspace
        $stmtBudgets = $db->prepare("
             SELECT b.id, b.category_id, b.amount, c.name as category_name, c.color as category_color, c.icon as category_icon
             FROM budgets b
             LEFT JOIN categories c ON b.category_id = c.id
             WHERE b.user_id = ? AND {$bWsCond} AND b.month = ? AND b.year = ?
        ");
        $bMonth = $startDate ? intval(date('m', strtotime($startDate))) : $month;
        $bYear = $startDate ? intval(date('Y', strtotime($startDate))) : $year;

        $stmtBudgets->execute([$userId, $bMonth, $bYear]);
        $budgetsRaw = $stmtBudgets->fetchAll();

        if ($startDate && $endDate) {
            $stmtSpent = $db->prepare("
                SELECT category_id, SUM(amount) as spent 
                FROM transactions 
                WHERE user_id = ? AND {$wsCond} AND type = 'egreso' AND date >= ? AND date <= ?
                GROUP BY category_id
            ");
            $stmtSpent->execute([$userId, $startDate, $endDate]);
        } else {
            $stmtSpent = $db->prepare("
                SELECT category_id, SUM(amount) as spent 
                FROM transactions 
                WHERE user_id = ? AND {$wsCond} AND type = 'egreso' AND MONTH(date) = ? AND YEAR(date) = ?
                GROUP BY category_id
            ");
            $stmtSpent->execute([$userId, $month, $year]);
        }
        $spentRaw = $stmtSpent->fetchAll();

        $spentMap = [];
        $totalSpent = 0.00;
        foreach ($spentRaw as $row) {
            $catId = $row['category_id'] !== null ? intval($row['category_id']) : 0;
            $spentMap[$catId] = floatval($row['spent']);
            $totalSpent += floatval($row['spent']);
        }

        $budgetsComparison = [];
        $sumLimits = 0.00;

        foreach ($budgetsRaw as $b) {
            if ($b['category_id'] !== null) {
                $limit = floatval($b['amount']);
                $sumLimits += $limit;
                
                $catId = intval($b['category_id']);
                $spent = isset($spentMap[$catId]) ? $spentMap[$catId] : 0.00;
                $budgetsComparison[] = [
                    "id" => $b['id'],
                    "category_id" => $catId,
                    "category_name" => $b['category_name'],
                    "category_color" => $b['category_color'],
                    "category_icon" => $b['category_icon'],
                    "limit" => $limit,
                    "spent" => $spent,
                    "percentage" => $limit > 0 ? round(($spent / $limit) * 100, 2) : 0
                ];
            }
        }

        $globalBudget = [
            "id" => null,
            "limit" => $sumLimits,
            "spent" => $totalSpent,
            "percentage" => $sumLimits > 0 ? round(($totalSpent / $sumLimits) * 100, 2) : 0
        ];

        // Consultas de depuración
        $stmtCount = $db->prepare("SELECT COUNT(*) as count FROM transactions WHERE user_id = ?");
        $stmtCount->execute([$userId]);
        $allCount = $stmtCount->fetch()['count'];

        $stmtAll = $db->prepare("SELECT id, type, amount, date FROM transactions WHERE user_id = ?");
        $stmtAll->execute([$userId]);
        $allTxs = $stmtAll->fetchAll();

        echo json_encode([
            "totals" => $totals,
            "categories" => $categoriesData,
            "daily_trends" => $dailyTrends,
            "savings" => $savingsData,
            "global_budget" => $globalBudget,
            "category_budgets" => $budgetsComparison,
            "debug" => [
                "user_id" => $userId,
                "server_month" => $month,
                "server_year" => $year,
                "total_count" => $allCount,
                "all_txs" => $allTxs
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al generar reportes: " . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Método HTTP no permitido."]);
