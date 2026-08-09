<?php
// backend/lib/budgets_logic.php
// Lógica de presupuestos extraída de budgets.php para que sea testeable de
// forma aislada (sin authenticate()/cors.php). Cada función recibe $db
// (PDO) y datos ya validados/parseados por el endpoint HTTP.

function budgets_get_for_period($db, $userId, $workspaceCondition, $month, $year, $inheritIfEmpty) {
    $stmt = $db->prepare("
        SELECT b.*, c.name as category_name, c.color as category_color, c.icon as category_icon
        FROM budgets b
        LEFT JOIN categories c ON b.category_id = c.id
        WHERE b.user_id = ? AND {$workspaceCondition} AND b.month = ? AND b.year = ?
        ORDER BY b.category_id IS NULL DESC, c.name ASC
    ");
    $stmt->execute([$userId, $month, $year]);
    $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Si no hay presupuestos creados para este mes específico, heredar los
    // del último mes configurado (solo para la vista "mes actual" implícita,
    // nunca cuando el usuario pidió explícitamente un mes/año concreto).
    if (empty($budgets) && $inheritIfEmpty) {
        $stmtLatest = $db->prepare("
            SELECT year, month
            FROM budgets b
            WHERE b.user_id = ? AND {$workspaceCondition}
            ORDER BY year DESC, month DESC
            LIMIT 1
        ");
        $stmtLatest->execute([$userId]);
        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
        if ($latest) {
            $stmt->execute([$userId, intval($latest['month']), intval($latest['year'])]);
            $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    foreach ($budgets as &$b) {
        $b['amount'] = floatval($b['amount']);
        $b['items'] = !empty($b['items_json']) ? json_decode($b['items_json'], true) : [];
    }

    return $budgets;
}

function budgets_copy_from_last_month($db, $userId, $workspace, $workspaceCondition, $currentMonth, $currentYear) {
    $stmtLatest = $db->prepare("
        SELECT year, month
        FROM budgets b
        WHERE b.user_id = ? AND {$workspaceCondition}
        ORDER BY year DESC, month DESC
        LIMIT 1
    ");
    $stmtLatest->execute([$userId]);
    $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);

    if (!$latest) {
        throw new RuntimeException("No se encontraron presupuestos anteriores para copiar.");
    }

    $stmtOld = $db->prepare("
        SELECT category_id, amount, items_json
        FROM budgets b
        WHERE b.user_id = ? AND {$workspaceCondition} AND b.month = ? AND b.year = ?
    ");
    $stmtOld->execute([$userId, intval($latest['month']), intval($latest['year'])]);
    $oldBudgets = $stmtOld->fetchAll(PDO::FETCH_ASSOC);

    $copiedCount = 0;
    foreach ($oldBudgets as $ob) {
        $catId = $ob['category_id'] !== null ? intval($ob['category_id']) : null;
        if ($catId === null) {
            $stmtCheck = $db->prepare("SELECT id FROM budgets WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND category_id IS NULL AND month = ? AND year = ?");
            $stmtCheck->execute([$userId, $workspace, $currentMonth, $currentYear]);
        } else {
            $stmtCheck = $db->prepare("SELECT id FROM budgets WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND category_id = ? AND month = ? AND year = ?");
            $stmtCheck->execute([$userId, $workspace, $catId, $currentMonth, $currentYear]);
        }

        if (!$stmtCheck->fetch()) {
            $stmtInsert = $db->prepare("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace, items_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$userId, $catId, $ob['amount'], $currentMonth, $currentYear, $workspace, $ob['items_json']]);
            $copiedCount++;
        }
    }

    return [
        "copied_count" => $copiedCount,
        "from_month" => intval($latest['month']),
        "from_year" => intval($latest['year'])
    ];
}

/**
 * Si el presupuesto viene desglosado en ítems, el monto total se calcula
 * sumándolos (en vez de usar el monto que haya mandado el formulario),
 * salvo que la suma dé 0, en cuyo caso se conserva el monto original.
 */
function budgets_calculate_amount_from_items($items, $fallbackAmount) {
    if (empty($items)) {
        return $fallbackAmount;
    }
    $sum = 0;
    foreach ($items as $item) {
        $sum += floatval($item['amount'] ?? 0);
    }
    return $sum > 0 ? $sum : $fallbackAmount;
}

function budgets_upsert($db, $userId, $workspace, $categoryId, $amount, $month, $year, $itemsJson) {
    if ($amount <= 0) {
        throw new InvalidArgumentException("El monto del presupuesto debe ser mayor a cero.");
    }

    if ($categoryId === null) {
        $stmtCheck = $db->prepare("SELECT id FROM budgets WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND category_id IS NULL AND month = ? AND year = ?");
        $stmtCheck->execute([$userId, $workspace, $month, $year]);
    } else {
        $stmtCheck = $db->prepare("SELECT id FROM budgets WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND category_id = ? AND month = ? AND year = ?");
        $stmtCheck->execute([$userId, $workspace, $categoryId, $month, $year]);
    }
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmtUpdate = $db->prepare("UPDATE budgets SET amount = ?, items_json = ? WHERE id = ?");
        $stmtUpdate->execute([$amount, $itemsJson, $existing['id']]);
        return ["id" => $existing['id'], "created" => false];
    }

    $stmtInsert = $db->prepare("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace, items_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtInsert->execute([$userId, $categoryId, $amount, $month, $year, $workspace, $itemsJson]);
    return ["id" => $db->lastInsertId(), "created" => true];
}

function budgets_delete($db, $userId, $budgetId) {
    $stmt = $db->prepare("DELETE FROM budgets WHERE id = ? AND user_id = ?");
    $stmt->execute([$budgetId, $userId]);
    return $stmt->rowCount();
}
