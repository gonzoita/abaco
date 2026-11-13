<?php
// backend/lib/finance_analytics.php
//
// Cálculos financieros que comparten la IA (ai.php), los puntos por ahorro y
// la analítica (insights.php). La IA es buena redactando y priorizando, pero
// mala sumando: aquí se le entregan las cifras YA calculadas (promedios,
// ejecución del presupuesto, meses de reserva, deudas) para que razone sobre
// números correctos en vez de estimarlos a partir de 10 movimientos sueltos.
//
// Todo el SQL usa rangos de fecha y funciones estándar (nada de MONTH()/YEAR())
// para que las mismas consultas corran en MySQL y en el SQLite de las pruebas.

require_once __DIR__ . '/budgets_logic.php';

// savings_add_funds() registra el abono a una meta como un 'egreso' con esta
// descripción. Es plata que el usuario AHORRÓ, no que gastó: contarla como
// gasto le bajaba la tasa de ahorro justo a quien más estaba ahorrando.
const FA_GOAL_CONTRIBUTION_PREFIX = 'Abono a meta de ahorro';

const FA_LIQUID_ACCOUNT_TYPES = ['efectivo', 'banco', 'ahorro'];
const FA_DEBT_ACCOUNT_TYPES = ['tarjeta_credito', 'prestamo_pagar'];

const FA_MONTH_NAMES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

const FA_LEVELS = [
    ['name' => 'Bronce', 'min' => 0],
    ['name' => 'Plata', 'min' => 500],
    ['name' => 'Oro', 'min' => 1500],
    ['name' => 'Platino', 'min' => 3000],
    ['name' => 'Diamante', 'min' => 5000],
];

const FA_EMERGENCY_MIN_MONTHS = 3;
const FA_EMERGENCY_FULL_MONTHS = 6;

function fa_ws_clause($column, $workspace) {
    if ($workspace === 'business') {
        return "(LOWER(COALESCE({$column}, 'personal')) = 'business')";
    }
    return "(LOWER(COALESCE({$column}, 'personal')) = 'personal' OR {$column} = '')";
}

function fa_month_range($year, $month) {
    $start = sprintf('%04d-%02d-01', $year, $month);
    return [$start, date('Y-m-t', strtotime($start))];
}

function fa_shift_month($year, $month, $delta) {
    $index = $year * 12 + ($month - 1) + $delta;
    return [intdiv($index, 12), $index % 12 + 1];
}

function fa_month_label($year, $month) {
    return FA_MONTH_NAMES[$month] . ' ' . $year;
}

function fa_money($amount) {
    return number_format(round(floatval($amount)), 0, ',', '.');
}

/** Tasa de ahorro (0.2 = 20%); null si no hubo ingresos contra los que medir. */
function fa_savings_rate($income, $expense) {
    if ($income <= 0) {
        return null;
    }
    return ($income - $expense) / $income;
}

/**
 * Ingresos, gastos y abonos a metas de un mes. 'expense' NO incluye los
 * abonos a metas de ahorro (ver FA_GOAL_CONTRIBUTION_PREFIX) ni las
 * transferencias entre cuentas propias.
 */
function fa_month_activity($db, $userId, $workspace, $year, $month) {
    [$start, $end] = fa_month_range($year, $month);
    $ws = fa_ws_clause('t.workspace', $workspace);
    $stmt = $db->prepare("
        SELECT t.type, t.category_id, c.name AS category_name,
               CASE WHEN t.type = 'egreso' AND t.description LIKE ? THEN 1 ELSE 0 END AS is_goal,
               SUM(t.amount) AS total
        FROM transactions t
        LEFT JOIN categories c ON t.category_id = c.id
        WHERE t.user_id = ? AND {$ws} AND t.date >= ? AND t.date <= ?
        GROUP BY t.type, t.category_id, c.name, is_goal
    ");
    $stmt->execute([FA_GOAL_CONTRIBUTION_PREFIX . '%', $userId, $start, $end]);

    $activity = ['year' => $year, 'month' => $month, 'income' => 0.0, 'expense' => 0.0, 'goal_contributions' => 0.0, 'by_category' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total = floatval($row['total']);
        if ($row['type'] === 'ingreso') {
            $activity['income'] += $total;
        } elseif ($row['type'] === 'egreso') {
            if (intval($row['is_goal']) === 1) {
                $activity['goal_contributions'] += $total;
                continue;
            }
            $activity['expense'] += $total;
            $catId = $row['category_id'] !== null ? intval($row['category_id']) : 0;
            if (!isset($activity['by_category'][$catId])) {
                $activity['by_category'][$catId] = ['name' => $row['category_name'] ?: 'Sin categoría', 'amount' => 0.0];
            }
            $activity['by_category'][$catId]['amount'] += $total;
        }
    }
    return $activity;
}

function fa_budget_rows($db, $userId, $workspace, $year, $month) {
    $ws = fa_ws_clause('b.workspace', $workspace);
    $stmt = $db->prepare("
        SELECT b.category_id, b.amount, c.name AS category_name
        FROM budgets b
        LEFT JOIN categories c ON b.category_id = c.id
        WHERE b.user_id = ? AND {$ws} AND b.month = ? AND b.year = ?
    ");
    $stmt->execute([$userId, $month, $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Cruza el presupuesto de un mes con lo realmente gastado. Si hay límites por
 * categoría, el total es su suma y se compara contra el gasto de ESAS
 * categorías; si solo existe el presupuesto global, se compara contra todo
 * el gasto del mes.
 */
function fa_budget_execution(array $budgetRows, array $activity) {
    $categories = [];
    $globalLimit = null;
    foreach ($budgetRows as $row) {
        if ($row['category_id'] === null) {
            $globalLimit = floatval($row['amount']);
            continue;
        }
        $catId = intval($row['category_id']);
        $limit = floatval($row['amount']);
        $spent = $activity['by_category'][$catId]['amount'] ?? 0.0;
        $categories[] = [
            'category_id' => $catId,
            'name' => $row['category_name'] ?: 'Sin categoría',
            'limit' => $limit,
            'spent' => $spent,
            'remaining' => $limit - $spent,
            'over' => $spent > $limit,
        ];
    }

    if (empty($categories) && $globalLimit === null) {
        return ['has_budget' => false, 'limit' => 0.0, 'spent' => 0.0, 'remaining' => 0.0, 'categories_over' => 0, 'categories' => []];
    }

    if (!empty($categories)) {
        $limit = array_sum(array_column($categories, 'limit'));
        $spent = array_sum(array_column($categories, 'spent'));
    } else {
        $limit = $globalLimit;
        $spent = $activity['expense'];
    }

    return [
        'has_budget' => true,
        'limit' => $limit,
        'spent' => $spent,
        'remaining' => $limit - $spent,
        'categories_over' => count(array_filter($categories, fn($c) => $c['over'])),
        'categories' => $categories,
    ];
}

/**
 * Gasto estimado al cierre del mes. Multiplicar el gasto diario por los días
 * del mes (lo que se hacía antes) se dispara cuando el arriendo o las cuotas
 * se pagan el día 1: el día 2 "proyectaba" 15 arriendos. Aquí se asume que lo
 * que falta del mes se comporta como un mes promedio, sin superar nunca la
 * proyección lineal. Sin historial solo queda la lineal.
 */
function fa_project_month_expense($spent, $day, $daysInMonth, $avgExpense) {
    $linear = $day > 0 ? $spent / $day * $daysInMonth : $spent;
    if ($avgExpense <= 0) {
        return $linear;
    }
    $blended = $spent + $avgExpense * ($daysInMonth - $day) / $daysInMonth;
    return max($spent, min($linear, $blended));
}

// ---------------------------------------------------------------------------
// Puntos por ahorro
// ---------------------------------------------------------------------------

/**
 * Puntos de UN mes. Premia lo que de verdad construye patrimonio: cuánto del
 * ingreso se ahorró, cumplir el presupuesto, abonar a metas y la constancia.
 * $m: income, expense, goal_contributions, budget (salida de
 * fa_budget_execution) y streak (meses seguidos ahorrando, contando este).
 */
function fa_points_for_month(array $m) {
    $breakdown = [];

    $rate = fa_savings_rate($m['income'], $m['expense']);
    if ($rate !== null && $rate > 0) {
        $breakdown[] = [
            'label' => 'Ahorraste el ' . round($rate * 100) . '% de tus ingresos',
            'points' => (int) min(500, round($rate * 1000)),
        ];
    }

    $budget = $m['budget'] ?? ['has_budget' => false];
    if ($budget['has_budget'] && $budget['limit'] > 0 && $budget['spent'] <= $budget['limit']) {
        $breakdown[] = ['label' => 'Cerraste dentro del presupuesto', 'points' => 100];
        if (!empty($budget['categories']) && $budget['categories_over'] === 0) {
            $breakdown[] = ['label' => 'Ninguna categoría se pasó de su límite', 'points' => 50];
        }
    }

    if (($m['goal_contributions'] ?? 0) > 0) {
        $breakdown[] = ['label' => 'Abonaste a tus metas de ahorro', 'points' => 50];
    }

    $streak = intval($m['streak'] ?? 0);
    if ($streak >= 2) {
        $breakdown[] = ['label' => "Racha de {$streak} meses seguidos ahorrando", 'points' => min(150, 25 * $streak)];
    }

    return ['total' => array_sum(array_column($breakdown, 'points')), 'breakdown' => $breakdown];
}

function fa_level_for_points($points) {
    $current = FA_LEVELS[0];
    $next = null;
    foreach (FA_LEVELS as $i => $level) {
        if ($points >= $level['min']) {
            $current = $level;
            $next = FA_LEVELS[$i + 1] ?? null;
        }
    }
    return [
        'name' => $current['name'],
        'min' => $current['min'],
        'next_name' => $next['name'] ?? null,
        'next_min' => $next['min'] ?? null,
        'points_to_next' => $next ? $next['min'] - $points : 0,
        'progress' => $next ? round(($points - $current['min']) / ($next['min'] - $current['min']), 3) : 1.0,
    ];
}

/**
 * Puntos de los últimos $monthsBack meses CERRADOS (los que suman al nivel)
 * más el mes en curso como "en juego": todavía no suma porque el mes no ha
 * terminado y un presupuesto "cumplido" el día 3 no significa nada.
 */
function fa_points_summary($db, $userId, $workspace, $today, $monthsBack = 12) {
    $curYear = intval(substr($today, 0, 4));
    $curMonth = intval(substr($today, 5, 2));

    $months = [];
    $streak = 0;
    $total = 0;
    for ($delta = -$monthsBack; $delta <= 0; $delta++) {
        [$y, $m] = fa_shift_month($curYear, $curMonth, $delta);
        $activity = fa_month_activity($db, $userId, $workspace, $y, $m);
        $budget = fa_budget_execution(fa_budget_rows($db, $userId, $workspace, $y, $m), $activity);
        $saved = $activity['income'] > 0 && $activity['income'] > $activity['expense'];
        $isCurrent = $delta === 0;

        $monthStreak = $saved ? $streak + 1 : 0;
        $points = fa_points_for_month([
            'income' => $activity['income'],
            'expense' => $activity['expense'],
            'goal_contributions' => $activity['goal_contributions'],
            'budget' => $budget,
            'streak' => $monthStreak,
        ]);

        $entry = [
            'year' => $y,
            'month' => $m,
            'label' => fa_month_label($y, $m),
            'income' => $activity['income'],
            'expense' => $activity['expense'],
            'saved' => $activity['income'] - $activity['expense'],
            'points' => $points['total'],
            'breakdown' => $points['breakdown'],
            'in_progress' => $isCurrent,
        ];

        if ($isCurrent) {
            $current = $entry;
        } else {
            $streak = $monthStreak;
            $total += $points['total'];
            if ($activity['income'] > 0 || $activity['expense'] > 0) {
                $months[] = $entry;
            }
        }
    }

    return [
        'total' => $total,
        'level' => fa_level_for_points($total),
        'streak' => $streak,
        'months' => array_reverse($months),
        'current_month' => $current,
    ];
}

// ---------------------------------------------------------------------------
// Inversión
// ---------------------------------------------------------------------------

/**
 * Decide EN CÓDIGO si el usuario está en condiciones de invertir y cuánto,
 * antes de pedirle nada a la IA. El orden no es negociable y por eso no se le
 * deja al modelo: primero la deuda de tarjeta (ninguna inversión segura rinde
 * más de lo que cobra una tarjeta), luego el fondo de emergencia, y solo
 * después invertir el excedente.
 *
 * $in: liquid, avg_monthly_expense, avg_monthly_surplus, debts[] (name, type, balance).
 */
function fa_investment_readiness(array $in) {
    $liquid = floatval($in['liquid']);
    $avgExpense = floatval($in['avg_monthly_expense']);
    $surplus = floatval($in['avg_monthly_surplus']);

    $cardDebt = 0.0;
    $debtTotal = 0.0;
    foreach ($in['debts'] ?? [] as $debt) {
        $debtTotal += $debt['balance'];
        if ($debt['type'] === 'tarjeta_credito') {
            $cardDebt += $debt['balance'];
        }
    }

    $emergencyMonths = $avgExpense > 0 ? $liquid / $avgExpense : null;
    $emergencyTarget = $avgExpense * FA_EMERGENCY_MIN_MONTHS;

    $result = [
        'emergency_months' => $emergencyMonths !== null ? round($emergencyMonths, 1) : null,
        'emergency_target' => $emergencyTarget,
        'emergency_gap' => max(0.0, $emergencyTarget - $liquid),
        'card_debt' => $cardDebt,
        'debt_total' => $debtTotal,
        'monthly_surplus' => $surplus,
        'investable_monthly' => 0.0,
        'investable_lump' => 0.0,
    ];

    if ($avgExpense <= 0) {
        return $result + [
            'stage' => 'sin_datos',
            'headline' => 'Todavía no hay suficientes movimientos registrados para saber cuánto puedes invertir.',
        ];
    }
    if ($cardDebt > 0) {
        return $result + [
            'stage' => 'pagar_deuda_cara',
            'headline' => 'Antes de invertir, paga la deuda de tarjeta de crédito (' . fa_money($cardDebt) . '): lo que cobra en intereses supera lo que rinde cualquier inversión segura.',
        ];
    }
    if ($emergencyMonths < FA_EMERGENCY_MIN_MONTHS) {
        return $result + [
            'stage' => 'fondo_emergencia',
            'headline' => 'Primero completa tu fondo de emergencia: tienes ' . round($emergencyMonths, 1) . ' meses de gastos cubiertos y el mínimo son ' . FA_EMERGENCY_MIN_MONTHS . '. Te faltan ' . fa_money($result['emergency_gap']) . '.',
        ];
    }

    // La reserva completa (6 meses) no se toca: solo se invierte lo que sobra.
    $result['investable_lump'] = max(0.0, $liquid - $avgExpense * FA_EMERGENCY_FULL_MONTHS);
    $result['investable_monthly'] = max(0.0, $surplus);

    if ($result['investable_monthly'] <= 0 && $result['investable_lump'] <= 0) {
        return $result + [
            'stage' => 'sin_excedente',
            'headline' => 'Tu fondo de emergencia está bien, pero hoy no te queda excedente mensual para invertir: el primer paso es liberar flujo recortando gastos o subiendo ingresos.',
        ];
    }
    return $result + [
        'stage' => 'listo',
        'headline' => 'Estás en condiciones de invertir: hasta ' . fa_money($result['investable_monthly']) . ' al mes'
            . ($result['investable_lump'] > 0 ? ' y ' . fa_money($result['investable_lump']) . ' que hoy tienes por encima de tu reserva de 6 meses.' : '.'),
    ];
}

// ---------------------------------------------------------------------------
// Contexto para la IA
// ---------------------------------------------------------------------------

function fa_accounts($db, $userId, $workspace) {
    $ws = fa_ws_clause('workspace', $workspace);
    $stmt = $db->prepare("SELECT name, type, balance, currency, credit_limit, interest_rate, term_months FROM accounts WHERE user_id = ? AND {$ws}");
    $stmt->execute([$userId]);

    $out = ['liquid' => [], 'debts' => [], 'other' => [], 'liquid_total' => 0.0, 'debt_total' => 0.0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $acc) {
        $balance = floatval($acc['balance']);
        if (in_array($acc['type'], FA_DEBT_ACCOUNT_TYPES, true)) {
            // La app guarda la deuda con signo negativo; aquí siempre es un monto positivo "que se debe".
            $owed = abs($balance);
            if ($owed > 0) {
                $out['debts'][] = [
                    'name' => $acc['name'],
                    'type' => $acc['type'],
                    'balance' => $owed,
                    'interest_rate' => $acc['interest_rate'] ?? null,
                    'credit_limit' => floatval($acc['credit_limit'] ?? 0),
                    'term_months' => $acc['term_months'] ?? null,
                ];
                $out['debt_total'] += $owed;
            }
        } elseif (in_array($acc['type'], FA_LIQUID_ACCOUNT_TYPES, true)) {
            $out['liquid'][] = ['name' => $acc['name'], 'type' => $acc['type'], 'balance' => $balance];
            $out['liquid_total'] += $balance;
        } else {
            $out['other'][] = ['name' => $acc['name'], 'type' => $acc['type'], 'balance' => $balance];
        }
    }
    return $out;
}

/** Plata que le deben al usuario (módulo de Préstamos = cartera por cobrar). */
function fa_receivables($db, $userId, $workspace, $today) {
    $ws = fa_ws_clause('l.workspace', $workspace);
    $stmt = $db->prepare("
        SELECT c.name AS client_name,
               SUM(i.installment) - SUM(i.paid_amount) AS remaining,
               SUM(CASE WHEN i.status <> 'pagado' AND i.date < ? THEN 1 ELSE 0 END) AS overdue_installments
        FROM loans l
        JOIN loan_clients c ON l.client_id = c.id
        JOIN loan_installments i ON i.loan_id = l.id
        WHERE l.user_id = ? AND {$ws} AND l.status <> 'finalizado'
        GROUP BY l.id, c.name
    ");
    $stmt->execute([$today, $userId]);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $remaining = max(0.0, floatval($row['remaining']));
        if ($remaining > 0) {
            $items[] = ['client' => $row['client_name'], 'remaining' => $remaining, 'overdue_installments' => intval($row['overdue_installments'])];
        }
    }
    return ['items' => $items, 'total' => array_sum(array_column($items, 'remaining'))];
}

function fa_savings_goals($db, $userId, $workspace, $today) {
    $ws = fa_ws_clause('workspace', $workspace);
    $stmt = $db->prepare("SELECT name, target_amount, current_amount, target_date FROM savings_goals WHERE user_id = ? AND {$ws}");
    $stmt->execute([$userId]);

    $goals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $target = floatval($g['target_amount']);
        $current = floatval($g['current_amount']);
        $missing = max(0.0, $target - $current);
        $monthlyNeeded = null;
        if ($missing > 0 && !empty($g['target_date']) && $g['target_date'] > $today) {
            $monthsLeft = max(1, (intval(substr($g['target_date'], 0, 4)) - intval(substr($today, 0, 4))) * 12
                + intval(substr($g['target_date'], 5, 2)) - intval(substr($today, 5, 2)));
            $monthlyNeeded = $missing / $monthsLeft;
        }
        $goals[] = [
            'name' => $g['name'],
            'target' => $target,
            'current' => $current,
            'missing' => $missing,
            'target_date' => $g['target_date'] ?: null,
            'monthly_needed' => $monthlyNeeded,
        ];
    }
    return $goals;
}

/**
 * Reúne toda la situación financiera de un espacio de trabajo. $today
 * ('Y-m-d') se recibe como parámetro para poder probarlo con fechas fijas.
 */
function fa_build_context($db, $userId, $workspace, $today) {
    $year = intval(substr($today, 0, 4));
    $month = intval(substr($today, 5, 2));
    $day = intval(substr($today, 8, 2));
    $daysInMonth = intval(date('t', strtotime($today)));

    $stmtUser = $db->prepare("SELECT currency FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $currency = $stmtUser->fetchColumn() ?: 'COP';

    // El presupuesto es el vigente: si el mes arrancó y nadie ha entrado a
    // Presupuestos, se trae aquí también para que la IA no vea "sin presupuesto".
    budgets_ensure_period_filled($db, $userId, $workspace, fa_ws_clause('b.workspace', $workspace), $month, $year);

    $history = [];
    for ($delta = -6; $delta <= -1; $delta++) {
        [$y, $m] = fa_shift_month($year, $month, $delta);
        $history[] = fa_month_activity($db, $userId, $workspace, $y, $m);
    }
    $current = fa_month_activity($db, $userId, $workspace, $year, $month);

    // Promedios sobre los 3 últimos meses cerrados que tengan movimientos (un
    // usuario nuevo no debe promediar contra meses vacíos).
    $recent = array_values(array_filter(array_slice($history, -3), fn($a) => $a['income'] > 0 || $a['expense'] > 0));
    $n = count($recent);
    $avgIncome = $n ? array_sum(array_column($recent, 'income')) / $n : 0.0;
    $avgExpense = $n ? array_sum(array_column($recent, 'expense')) / $n : 0.0;
    $avgByCategory = [];
    foreach ($recent as $a) {
        foreach ($a['by_category'] as $catId => $cat) {
            $avgByCategory[$catId] = ($avgByCategory[$catId] ?? 0.0) + $cat['amount'] / $n;
        }
    }

    $budget = fa_budget_execution(fa_budget_rows($db, $userId, $workspace, $year, $month), $current);
    $budgetByCat = [];
    foreach ($budget['categories'] as $bc) {
        $budgetByCat[$bc['category_id']] = $bc;
    }

    $categories = [];
    $catIds = array_unique(array_merge(array_keys($current['by_category']), array_keys($budgetByCat), array_keys($avgByCategory)));
    foreach ($catIds as $catId) {
        $name = $current['by_category'][$catId]['name'] ?? $budgetByCat[$catId]['name'] ?? null;
        if ($name === null) {
            foreach ($recent as $a) {
                if (isset($a['by_category'][$catId])) {
                    $name = $a['by_category'][$catId]['name'];
                }
            }
        }
        $categories[] = [
            'name' => $name,
            'spent' => $current['by_category'][$catId]['amount'] ?? 0.0,
            'avg_3m' => $avgByCategory[$catId] ?? 0.0,
            'budget' => $budgetByCat[$catId]['limit'] ?? null,
        ];
    }
    usort($categories, fn($a, $b) => max($b['spent'], $b['avg_3m']) <=> max($a['spent'], $a['avg_3m']));

    $accounts = fa_accounts($db, $userId, $workspace);
    $projectedExpense = fa_project_month_expense($current['expense'], $day, $daysInMonth, $avgExpense);
    $expenseBase = $avgExpense > 0 ? $avgExpense : $projectedExpense;

    $ws = fa_ws_clause('t.workspace', $workspace);
    [$monthStart, $monthEnd] = fa_month_range($year, $month);
    $stmtTop = $db->prepare("
        SELECT t.date, t.amount, t.description, c.name AS category
        FROM transactions t LEFT JOIN categories c ON t.category_id = c.id
        WHERE t.user_id = ? AND {$ws} AND t.type = 'egreso' AND t.date >= ? AND t.date <= ? AND COALESCE(t.description, '') NOT LIKE ?
        ORDER BY t.amount DESC LIMIT 5
    ");
    $stmtTop->execute([$userId, $monthStart, $monthEnd, FA_GOAL_CONTRIBUTION_PREFIX . '%']);

    $stmtRecent = $db->prepare("
        SELECT t.date, t.type, t.amount, t.description, c.name AS category
        FROM transactions t LEFT JOIN categories c ON t.category_id = c.id
        WHERE t.user_id = ? AND {$ws}
        ORDER BY t.date DESC, t.id DESC LIMIT 15
    ");
    $stmtRecent->execute([$userId]);

    try {
        $receivables = fa_receivables($db, $userId, $workspace, $today);
    } catch (PDOException $e) {
        // Las tablas de préstamos se crean con un script aparte
        // (database/loans_schema.sql) y pueden no existir en una instalación.
        $receivables = ['items' => [], 'total' => 0.0];
    }

    return [
        'workspace' => $workspace,
        'currency' => $currency,
        'today' => $today,
        'day' => $day,
        'days_in_month' => $daysInMonth,
        'month_label' => fa_month_label($year, $month),
        'accounts' => $accounts,
        'history' => $history,
        'current' => $current,
        'avg_income' => $avgIncome,
        'avg_expense' => $avgExpense,
        'months_averaged' => $n,
        'expense_base' => $expenseBase,
        'projected_expense' => $projectedExpense,
        'projected_income' => max($current['income'], $avgIncome),
        'budget' => $budget,
        'categories' => $categories,
        'top_expenses' => $stmtTop->fetchAll(PDO::FETCH_ASSOC),
        'recent' => $stmtRecent->fetchAll(PDO::FETCH_ASSOC),
        'goals' => fa_savings_goals($db, $userId, $workspace, $today),
        'receivables' => $receivables,
        'points' => fa_points_summary($db, $userId, $workspace, $today),
        'readiness' => fa_investment_readiness([
            'liquid' => $accounts['liquid_total'],
            'avg_monthly_expense' => $expenseBase,
            'avg_monthly_surplus' => $n ? $avgIncome - $avgExpense : 0.0,
            'debts' => $accounts['debts'],
        ]),
    ];
}

/** Convierte el contexto en el texto que se inyecta en {{RESUMEN_FINANCIERO}}. */
function fa_context_to_text(array $ctx) {
    $cur = $ctx['currency'];
    $isBusiness = $ctx['workspace'] === 'business';
    $daysLeft = $ctx['days_in_month'] - $ctx['day'];
    $c = $ctx['current'];
    $L = [];

    $L[] = 'ESPACIO: ' . ($isBusiness ? 'NEGOCIO (cifras del negocio, separadas de las personales)' : 'PERSONAL') . " | Moneda: {$cur}";
    $L[] = "FECHA: {$ctx['today']} (día {$ctx['day']} de {$ctx['days_in_month']} de {$ctx['month_label']}; faltan {$daysLeft} días para el cierre)";
    $L[] = 'Todas las cifras de abajo ya están calculadas por el sistema a partir de los registros reales: úsalas tal cual, no las recalcules ni inventes otras.';

    $L[] = '';
    $L[] = 'LIQUIDEZ (' . fa_money($ctx['accounts']['liquid_total']) . ' en total):';
    foreach ($ctx['accounts']['liquid'] as $a) {
        $L[] = "- {$a['name']} ({$a['type']}): " . fa_money($a['balance']);
    }
    foreach ($ctx['accounts']['other'] as $a) {
        $L[] = "- {$a['name']} ({$a['type']}, no cuenta como líquido): " . fa_money($a['balance']);
    }

    $L[] = '';
    if (empty($ctx['accounts']['debts'])) {
        $L[] = 'DEUDAS: ninguna registrada.';
    } else {
        $L[] = 'DEUDAS (' . fa_money($ctx['accounts']['debt_total']) . ' en total):';
        foreach ($ctx['accounts']['debts'] as $d) {
            $extra = [];
            if (!empty($d['interest_rate'])) $extra[] = "tasa registrada: {$d['interest_rate']}";
            if ($d['credit_limit'] > 0) $extra[] = 'cupo ' . fa_money($d['credit_limit']) . ' (uso ' . round($d['balance'] / $d['credit_limit'] * 100) . '%)';
            if (!empty($d['term_months'])) $extra[] = "plazo {$d['term_months']} meses";
            $tipo = $d['type'] === 'tarjeta_credito' ? 'tarjeta de crédito' : 'préstamo por pagar';
            $L[] = "- {$d['name']} ({$tipo}): debe " . fa_money($d['balance']) . ($extra ? ' [' . implode('; ', $extra) . ']' : '');
        }
    }

    if (!empty($ctx['receivables']['items'])) {
        $L[] = '';
        $L[] = ($isBusiness ? 'CARTERA POR COBRAR A CLIENTES' : 'PLATA QUE LE DEBEN AL USUARIO') . ' (' . fa_money($ctx['receivables']['total']) . ' en total):';
        foreach ($ctx['receivables']['items'] as $r) {
            $L[] = "- {$r['client']}: " . fa_money($r['remaining']) . ($r['overdue_installments'] > 0 ? " — {$r['overdue_installments']} cuota(s) VENCIDA(S)" : ' — al día');
        }
    }

    $L[] = '';
    $L[] = 'HISTORIAL MENSUAL (meses cerrados; "ahorro" = ingresos - gastos; los abonos a metas NO cuentan como gasto):';
    $anyHistory = false;
    foreach ($ctx['history'] as $h) {
        if ($h['income'] <= 0 && $h['expense'] <= 0) continue;
        $anyHistory = true;
        $rate = fa_savings_rate($h['income'], $h['expense']);
        $L[] = '- ' . fa_month_label($h['year'], $h['month']) . ': ingresos ' . fa_money($h['income']) . ' | gastos ' . fa_money($h['expense'])
            . ' | ' . ($isBusiness ? 'utilidad' : 'ahorro') . ' ' . fa_money($h['income'] - $h['expense'])
            . ($rate !== null ? ' (' . round($rate * 100) . '% del ingreso)' : '')
            . ($h['goal_contributions'] > 0 ? ' | abonado a metas ' . fa_money($h['goal_contributions']) : '');
    }
    if (!$anyHistory) {
        $L[] = '- Sin meses anteriores con movimientos: el usuario es nuevo o apenas empieza a registrar.';
    }
    if ($ctx['months_averaged'] > 0) {
        $L[] = "PROMEDIO de los últimos {$ctx['months_averaged']} mes(es) cerrados: ingresos " . fa_money($ctx['avg_income']) . ' | gastos ' . fa_money($ctx['avg_expense'])
            . ' | excedente ' . fa_money($ctx['avg_income'] - $ctx['avg_expense']);
    }

    $L[] = '';
    $L[] = "MES EN CURSO ({$ctx['month_label']}, va el día {$ctx['day']}):";
    $L[] = '- Ingresos a la fecha: ' . fa_money($c['income']) . ' | Gastos a la fecha: ' . fa_money($c['expense'])
        . ($c['goal_contributions'] > 0 ? ' | Abonado a metas: ' . fa_money($c['goal_contributions']) : '');
    $L[] = '- Cierre estimado del mes: gastos ' . fa_money($ctx['projected_expense']) . ' | ingresos ' . fa_money($ctx['projected_income'])
        . ' | resultado ' . fa_money($ctx['projected_income'] - $ctx['projected_expense']) . ' (es una estimación, no un dato)';

    $b = $ctx['budget'];
    $L[] = '';
    if (!$b['has_budget']) {
        $L[] = 'PRESUPUESTO: el usuario no tiene presupuesto definido.';
    } else {
        $L[] = 'PRESUPUESTO VIGENTE DEL MES: límite ' . fa_money($b['limit']) . ' | gastado ' . fa_money($b['spent'])
            . ' (' . round($b['limit'] > 0 ? $b['spent'] / $b['limit'] * 100 : 0) . '%) | disponible ' . fa_money($b['remaining'])
            . ($daysLeft > 0 && $b['remaining'] > 0 ? ' = ' . fa_money($b['remaining'] / $daysLeft) . ' por día hasta el cierre' : '');
        $L[] = 'Referencia: ha pasado el ' . round($ctx['day'] / $ctx['days_in_month'] * 100) . '% del mes.';
    }

    $L[] = '';
    $L[] = 'GASTO POR CATEGORÍA (este mes | promedio mensual últimos meses | presupuesto):';
    foreach ($ctx['categories'] as $cat) {
        $line = "- {$cat['name']}: " . fa_money($cat['spent']) . ' | prom. ' . fa_money($cat['avg_3m']);
        if ($cat['budget'] !== null) {
            $pct = $cat['budget'] > 0 ? round($cat['spent'] / $cat['budget'] * 100) : 0;
            $line .= ' | presupuesto ' . fa_money($cat['budget']) . " ({$pct}% usado" . ($cat['spent'] > $cat['budget'] ? ', EXCEDIDO por ' . fa_money($cat['spent'] - $cat['budget']) : '') . ')';
        } else {
            $line .= ' | sin presupuesto';
        }
        if ($cat['avg_3m'] > 0 && $cat['spent'] > $cat['avg_3m'] * 1.25) {
            $line .= ' ⚠ ' . round(($cat['spent'] / $cat['avg_3m'] - 1) * 100) . '% por encima de lo habitual';
        }
        $L[] = $line;
    }
    if (empty($ctx['categories'])) {
        $L[] = '- Sin gastos registrados.';
    }

    if (!empty($ctx['top_expenses'])) {
        $L[] = '';
        $L[] = 'LOS GASTOS MÁS GRANDES DEL MES:';
        foreach ($ctx['top_expenses'] as $t) {
            $L[] = "- {$t['date']}: " . fa_money($t['amount']) . " — {$t['description']} (" . ($t['category'] ?: 'sin categoría') . ')';
        }
    }

    if (!empty($ctx['goals'])) {
        $L[] = '';
        $L[] = 'METAS DE AHORRO:';
        foreach ($ctx['goals'] as $g) {
            $pct = $g['target'] > 0 ? round($g['current'] / $g['target'] * 100) : 0;
            $L[] = "- {$g['name']}: " . fa_money($g['current']) . ' de ' . fa_money($g['target']) . " ({$pct}%)"
                . ($g['target_date'] ? ", fecha objetivo {$g['target_date']}" : ', sin fecha')
                . ($g['monthly_needed'] !== null ? ', necesita ' . fa_money($g['monthly_needed']) . '/mes para llegar a tiempo' : '');
        }
    }

    $r = $ctx['readiness'];
    $L[] = '';
    $L[] = 'RESERVA DE EMERGENCIA: ' . ($r['emergency_months'] !== null
        ? "{$r['emergency_months']} meses de gastos cubiertos con la liquidez actual (mínimo sano: " . FA_EMERGENCY_MIN_MONTHS . ', ideal: ' . FA_EMERGENCY_FULL_MONTHS . ')'
        : 'no calculable todavía (faltan gastos registrados)');
    $L[] = "CAPACIDAD DE INVERSIÓN (calculada por el sistema, respétala): etapa «{$r['stage']}». {$r['headline']}";

    $p = $ctx['points'];
    $L[] = '';
    $L[] = "PUNTOS POR AHORRO: {$p['total']} puntos en los últimos 12 meses — nivel {$p['level']['name']}"
        . ($p['level']['next_name'] ? " (le faltan {$p['level']['points_to_next']} para {$p['level']['next_name']})" : ' (nivel máximo)')
        . " | racha: {$p['streak']} mes(es) seguidos ahorrando | este mes lleva {$p['current_month']['points']} puntos en juego.";

    $L[] = '';
    $L[] = 'ÚLTIMOS MOVIMIENTOS:';
    foreach ($ctx['recent'] as $t) {
        $L[] = "- {$t['date']} | {$t['type']} | " . fa_money($t['amount']) . " | {$t['description']} (" . ($t['category'] ?: 'sin categoría') . ')';
    }
    if (empty($ctx['recent'])) {
        $L[] = '- Ninguno todavía.';
    }

    return implode("\n", $L);
}
