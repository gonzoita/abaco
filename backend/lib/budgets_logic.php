<?php
// backend/lib/budgets_logic.php
// Lógica de presupuestos extraída de budgets.php para que sea testeable de
// forma aislada (sin authenticate()/cors.php). Cada función recibe $db
// (PDO) y datos ya validados/parseados por el endpoint HTTP.

/**
 * Combina las filas de presupuesto del mes actual con las del período
 * anterior, agregando SOLO las categorías del período anterior que el mes
 * actual todavía no tiene fila propia -- nunca pisa ni duplica una
 * categoría que el mes actual ya tiene. Si $currentRows viene vacío,
 * el resultado es simplemente todo $priorRows (mes recién empezado).
 *
 * Función pura (sin acceso a BD) para poder testearla sin fixtures de
 * base de datos, y para que budgets_get_for_period() y reports.php
 * compartan exactamente la misma regla de "completar lo que falta" en vez
 * de tener cada uno su propia lógica de herencia que se puede desalinear.
 */
function budgets_merge_gap_categories($currentRows, $priorRows) {
    if (empty($priorRows)) {
        return $currentRows;
    }
    $existingKeys = array_map(
        fn($b) => $b['category_id'] === null ? 'null' : intval($b['category_id']),
        $currentRows
    );
    $merged = $currentRows;
    foreach ($priorRows as $pb) {
        $key = $pb['category_id'] === null ? 'null' : intval($pb['category_id']);
        if (!in_array($key, $existingKeys, true)) {
            $merged[] = $pb;
        }
    }
    return $merged;
}

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

    // Completar con el último período configurado ANTES de este mes/año --
    // solo para la vista "mes actual" implícita, nunca cuando el usuario
    // pidió explícitamente un mes/año concreto. Antes esto solo se activaba
    // si el mes estaba 100% vacío: apenas existía UNA fila real (por
    // ejemplo, tras editar una sola categoría), el resto de categorías
    // dejaba de heredarse y parecía "borrado" sin que nadie las tocara.
    // budgets_merge_gap_categories() completa por categoría individual, así
    // que un mes parcial nunca pierde de vista lo que todavía no se editó.
    if ($inheritIfEmpty) {
        $stmtLatest = $db->prepare("
            SELECT year, month
            FROM budgets b
            WHERE b.user_id = ? AND {$workspaceCondition}
              AND (b.year < ? OR (b.year = ? AND b.month < ?))
            ORDER BY year DESC, month DESC
            LIMIT 1
        ");
        $stmtLatest->execute([$userId, $year, $year, $month]);
        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
        if ($latest) {
            $stmt->execute([$userId, intval($latest['month']), intval($latest['year'])]);
            $priorBudgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $budgets = budgets_merge_gap_categories($budgets, $priorBudgets);
        }
    }

    usort($budgets, function ($a, $b) {
        $aGlobal = $a['category_id'] === null;
        $bGlobal = $b['category_id'] === null;
        if ($aGlobal !== $bGlobal) {
            return $aGlobal ? -1 : 1;
        }
        return strcmp($a['category_name'] ?? '', $b['category_name'] ?? '');
    });

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

function budgets_upsert($db, $userId, $workspace, $categoryId, $amount, $month, $year, $itemsJson, $workspaceCondition) {
    if ($amount <= 0) {
        throw new InvalidArgumentException("El monto del presupuesto debe ser mayor a cero.");
    }

    // Presupuestos recurrentes: si este es el primer toque de este mes (el
    // usuario todavía no tiene NINGUNA fila para user+workspace+month+year),
    // primero se copia el resto de categorías del último mes configurado.
    // Antes, la pantalla de Presupuestos solo "heredaba" del mes anterior
    // mientras el mes actual estuviera 100% vacío (ver budgets_get_for_period
    // y reports.php): apenas se creaba UNA fila real -- por ejemplo al editar
    // una sola categoría -- esa herencia se cortaba de golpe para todas las
    // demás, que parecían haberse "borrado" sin que nadie las tocara. Al
    // materializar el resto del mes ANTES del upsert puntual, el usuario
    // nunca pierde de vista categorías que no editó explícitamente.
    $carriedOverCount = 0;
    $stmtAny = $db->prepare("SELECT COUNT(*) FROM budgets WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND month = ? AND year = ?");
    $stmtAny->execute([$userId, $workspace, $month, $year]);
    if (intval($stmtAny->fetchColumn()) === 0) {
        try {
            $carryOver = budgets_copy_from_last_month($db, $userId, $workspace, $workspaceCondition, $month, $year);
            $carriedOverCount = $carryOver['copied_count'];
        } catch (RuntimeException $e) {
            // No hay presupuestos de meses anteriores que copiar (usuario
            // nuevo o primera vez que usa presupuestos). Seguir normalmente.
        }
    }

    if ($categoryId === null) {
        $stmtCheck = $db->prepare("SELECT id FROM budgets WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND category_id IS NULL AND month = ? AND year = ?");
        $stmtCheck->execute([$userId, $workspace, $month, $year]);
    } else {
        $stmtCheck = $db->prepare("SELECT id FROM budgets WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND category_id = ? AND month = ? AND year = ?");
        $stmtCheck->execute([$userId, $workspace, $categoryId, $month, $year]);
    }
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    // Si la categoría editada fue una de las que se acaba de copiar del mes
    // pasado, esto la encuentra y la ACTUALIZA al monto nuevo que mandó el
    // usuario -- el carry-over nunca pisa la edición explícita que disparó
    // todo esto. Para el aviso al usuario, esa categoría no cuenta como
    // "traída" (fue una copia de paso, de inmediato pisada por su propio
    // monto nuevo): se descuenta del total si vino del carry-over.
    if ($existing) {
        if ($carriedOverCount > 0) {
            $carriedOverCount = max(0, $carriedOverCount - 1);
        }
        $stmtUpdate = $db->prepare("UPDATE budgets SET amount = ?, items_json = ? WHERE id = ?");
        $stmtUpdate->execute([$amount, $itemsJson, $existing['id']]);
        return ["id" => $existing['id'], "created" => false, "carried_over_count" => $carriedOverCount];
    }

    $stmtInsert = $db->prepare("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace, items_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtInsert->execute([$userId, $categoryId, $amount, $month, $year, $workspace, $itemsJson]);
    return ["id" => $db->lastInsertId(), "created" => true, "carried_over_count" => $carriedOverCount];
}

function budgets_delete($db, $userId, $budgetId) {
    $stmt = $db->prepare("DELETE FROM budgets WHERE id = ? AND user_id = ?");
    $stmt->execute([$budgetId, $userId]);
    return $stmt->rowCount();
}
