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

function budgets_copy_from_last_month($db, $userId, $workspace, $workspaceCondition, $currentMonth, $currentYear, $fromMonth = null, $fromYear = null) {
    // El período de origen tiene que ser ESTRICTAMENTE anterior al destino.
    // Sin este filtro la consulta encontraba al propio mes destino cuando ya
    // tenía al menos una fila: entonces "copiaba" esa única categoría sobre
    // sí misma, veía que ya existía y terminaba con copied_count = 0. Ese era
    // el motivo por el que el botón "traer el presupuesto del mes pasado" no
    // hacía nada justo después de editar una sola categoría del mes nuevo.
    //
    // Con $fromMonth/$fromYear el usuario elige de qué mes traer: el último
    // período puede ser justamente el incompleto (un mes en el que solo se
    // editó una categoría), y entonces "el anterior" no sirve de fuente.
    if ($fromMonth !== null && $fromYear !== null) {
        $latest = ['month' => intval($fromMonth), 'year' => intval($fromYear)];
        if ($latest['year'] > $currentYear || ($latest['year'] === $currentYear && $latest['month'] >= $currentMonth)) {
            throw new InvalidArgumentException("Solo se puede traer el presupuesto de un mes anterior al que estás configurando.");
        }
        $stmtExists = $db->prepare("SELECT COUNT(*) FROM budgets b WHERE b.user_id = ? AND {$workspaceCondition} AND b.month = ? AND b.year = ?");
        $stmtExists->execute([$userId, $latest['month'], $latest['year']]);
        if (intval($stmtExists->fetchColumn()) === 0) {
            throw new RuntimeException("Ese mes no tiene presupuesto para copiar.");
        }
    } else {
        $stmtLatest = $db->prepare("
            SELECT year, month
            FROM budgets b
            WHERE b.user_id = ? AND {$workspaceCondition}
              AND (b.year < ? OR (b.year = ? AND b.month < ?))
            ORDER BY year DESC, month DESC
            LIMIT 1
        ");
        $stmtLatest->execute([$userId, $currentYear, $currentYear, $currentMonth]);
        $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);

        if (!$latest) {
            throw new RuntimeException("No se encontraron presupuestos anteriores para copiar.");
        }
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

function budgets_period_is_empty($db, $userId, $workspace, $month, $year) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM budgets WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND month = ? AND year = ?");
    $stmt->execute([$userId, $workspace, $month, $year]);
    return intval($stmt->fetchColumn()) === 0;
}

/**
 * Meses anteriores al indicado que tienen presupuesto, del más reciente al
 * más antiguo (hasta 12), con cuántas categorías y cuánto suman, y cuántas de
 * esas categorías le faltan todavía al mes destino. Sirve para que el
 * usuario elija de cuál traer lo que le falta.
 */
function budgets_list_periods($db, $userId, $workspaceCondition, $month, $year) {
    $stmt = $db->prepare("
        SELECT b.year, b.month, b.category_id, b.amount
        FROM budgets b
        WHERE b.user_id = ? AND {$workspaceCondition}
          AND (b.year < ? OR (b.year = ? AND b.month < ?))
        ORDER BY b.year DESC, b.month DESC
    ");
    $stmt->execute([$userId, $year, $year, $month]);

    $stmtHere = $db->prepare("SELECT b.category_id FROM budgets b WHERE b.user_id = ? AND {$workspaceCondition} AND b.month = ? AND b.year = ?");
    $stmtHere->execute([$userId, $month, $year]);
    $here = [];
    foreach ($stmtHere->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $here[$row['category_id'] === null ? 'null' : intval($row['category_id'])] = true;
    }

    $periods = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = intval($row['year']) . '-' . intval($row['month']);
        if (!isset($periods[$key])) {
            if (count($periods) >= 12) {
                continue;
            }
            $periods[$key] = ['month' => intval($row['month']), 'year' => intval($row['year']), 'category_count' => 0, 'total' => 0.0, 'missing_count' => 0];
        }
        $catKey = $row['category_id'] === null ? 'null' : intval($row['category_id']);
        $periods[$key]['category_count']++;
        // El presupuesto global (sin categoría) es un tope aparte, no se suma a los límites por categoría.
        if ($row['category_id'] !== null) {
            $periods[$key]['total'] += floatval($row['amount']);
        }
        if (!isset($here[$catKey])) {
            $periods[$key]['missing_count']++;
        }
    }

    return array_values($periods);
}

/**
 * Arranca el mes con el presupuesto del período anterior ya guardado de
 * verdad en la BD, no solo "heredado" al momento de mostrarlo. Sin esto, cada
 * 1ro de mes el presupuesto se veía vacío hasta que el usuario editaba algo o
 * apretaba el botón de traer el mes pasado: el presupuesto mensual casi
 * siempre es el mismo, así que lo correcto es que esté ahí desde el primer
 * día y que ajustarlo sea lo manual, no tener que recuperarlo.
 *
 * Solo actúa cuando el mes está COMPLETAMENTE vacío. Si ya tiene filas
 * propias se respeta tal cual lo dejó el usuario -- incluida una categoría
 * que borró a propósito, que no debe reaparecer sola en la siguiente recarga.
 * Para esos meses parciales el botón de traer el mes pasado sigue siendo la
 * forma explícita de completar lo que falte.
 */
function budgets_ensure_period_filled($db, $userId, $workspace, $workspaceCondition, $month, $year) {
    if (!budgets_period_is_empty($db, $userId, $workspace, $month, $year)) {
        return 0;
    }

    try {
        $result = budgets_copy_from_last_month($db, $userId, $workspace, $workspaceCondition, $month, $year);
        return $result['copied_count'];
    } catch (RuntimeException $e) {
        // Usuario nuevo: no hay ningún mes anterior del que traer nada.
        return 0;
    }
}

/**
 * El presupuesto es "el vigente": se mantiene igual mes a mes hasta que el
 * usuario lo ajuste. Esto le dice a la pantalla si el mes actual sigue
 * idéntico al último período anterior (mismo límite en las mismas
 * categorías) o si ya fue ajustado para este mes.
 */
function budgets_standing_status($db, $userId, $workspaceCondition, $month, $year) {
    $limitsOf = function ($m, $y) use ($db, $userId, $workspaceCondition) {
        $stmt = $db->prepare("SELECT b.category_id, b.amount FROM budgets b WHERE b.user_id = ? AND {$workspaceCondition} AND b.month = ? AND b.year = ?");
        $stmt->execute([$userId, $m, $y]);
        $limits = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['category_id'] === null ? 'global' : intval($row['category_id']);
            $limits[$key] = round(floatval($row['amount']), 2);
        }
        ksort($limits);
        return $limits;
    };

    $current = $limitsOf($month, $year);
    if (empty($current)) {
        return ['has_budget' => false, 'previous' => null, 'unchanged' => false];
    }

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
    if (!$latest) {
        return ['has_budget' => true, 'previous' => null, 'unchanged' => false];
    }

    $previous = $limitsOf(intval($latest['month']), intval($latest['year']));
    return [
        'has_budget' => true,
        'previous' => ['month' => intval($latest['month']), 'year' => intval($latest['year'])],
        'unchanged' => $current == $previous,
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
    if (budgets_period_is_empty($db, $userId, $workspace, $month, $year)) {
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

/**
 * El mes que se está viendo puede mostrar filas heredadas que en realidad
 * pertenecen a un mes anterior (vienen con el id de ESA fila). Sin exigir que
 * el mes/año coincidan, borrar una de esas desde la pantalla del mes actual
 * borraba el presupuesto del mes pasado -- justo la fuente de la que todo lo
 * demás se hereda, así que el presupuesto se degradaba mes a mes.
 */
function budgets_delete($db, $userId, $budgetId, $month, $year) {
    $stmt = $db->prepare("DELETE FROM budgets WHERE id = ? AND user_id = ? AND month = ? AND year = ?");
    $stmt->execute([$budgetId, $userId, $month, $year]);
    return $stmt->rowCount();
}
