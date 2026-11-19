<?php
// backend/lib/workspace_data.php
//
// Independencia entre los espacios de trabajo "personal" y "business": las
// categorías, los clientes de préstamos, los recordatorios y los respaldos de
// un espacio no se ven ni se tocan desde el otro.
//
// Funciones puras sobre PDO (sin autenticación ni HTTP) para poder probarlas
// con SQLite. Las que reparan datos existentes son idempotentes (correrlas
// dos veces no cambia nada) y dejan cada cambio en workspace_fix_log para
// poder revertirlo a mano si hiciera falta.

const WS_BUSINESS_DEFAULT_CATEGORIES = [
    ['Ventas', 'fa-cash-register', '#10b981', 'ingreso'],
    ['Servicios prestados', 'fa-handshake', '#14b8a6', 'ingreso'],
    ['Otros ingresos', 'fa-circle-plus', '#34d399', 'ingreso'],
    ['Proveedores', 'fa-truck-ramp-box', '#f59e0b', 'egreso'],
    ['Nómina', 'fa-users', '#6366f1', 'egreso'],
    ['Arriendo del local', 'fa-store', '#ef4444', 'egreso'],
    ['Servicios públicos', 'fa-bolt', '#3b82f6', 'egreso'],
    ['Impuestos', 'fa-landmark', '#8b5cf6', 'egreso'],
    ['Publicidad', 'fa-bullhorn', '#ec4899', 'egreso'],
    ['Transporte y logística', 'fa-truck-fast', '#0ea5e9', 'egreso'],
    ['Gastos Bancarios', 'fa-building-columns', '#64748b', 'egreso'],
    ['Otros gastos', 'fa-ellipsis', '#6b7280', 'egreso'],
];

/** Cualquier valor raro o vacío cuenta como espacio personal (así se migró todo lo anterior). */
function ws_norm($workspace) {
    return strtolower(trim((string) $workspace)) === 'business' ? 'business' : 'personal';
}

/** Expresión SQL con el espacio de una columna, normalizado a 'personal' / 'business'. */
function ws_sql($column) {
    return "LOWER(COALESCE(NULLIF({$column}, ''), 'personal'))";
}

/** Deja constancia de un cambio de datos; si la tabla de bitácora no existe, no estorba. */
function ws_log_fix(PDO $db, $table, $rowId, $column, $old, $new) {
    try {
        $stmt = $db->prepare("INSERT INTO workspace_fix_log (table_name, row_id, column_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$table, $rowId, $column, (string) $old, (string) $new]);
    } catch (PDOException $e) {
        // Sin bitácora el cambio igual se aplica.
    }
}

/**
 * Categorías de arranque del negocio, propias del usuario y UNA sola vez por
 * usuario (queda constancia en user_workspace_seeds): si borra una, no vuelve
 * a aparecer. Se siembran por usuario y no como filas compartidas porque
 * editar o borrar una categoría compartida le cambiaría el negocio a todos.
 * No duplica las que ya existan con el mismo nombre. Devuelve cuántas creó.
 */
function ws_seed_business_categories(PDO $db, $userId) {
    $canMark = true;
    try {
        $stmt = $db->prepare("SELECT 1 FROM user_workspace_seeds WHERE user_id = ? AND workspace = 'business'");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn()) {
            return 0;
        }
    } catch (PDOException $e) {
        $canMark = false;
    }

    $created = 0;
    $stmtFind = $db->prepare("SELECT 1 FROM categories WHERE user_id = ? AND " . ws_sql('workspace') . " = 'business' AND LOWER(name) = LOWER(?)");
    $stmtInsert = $db->prepare("INSERT INTO categories (user_id, name, icon, color, type, workspace) VALUES (?, ?, ?, ?, ?, 'business')");
    foreach (WS_BUSINESS_DEFAULT_CATEGORIES as [$name, $icon, $color, $type]) {
        $stmtFind->execute([$userId, $name]);
        if (!$stmtFind->fetchColumn()) {
            $stmtInsert->execute([$userId, $name, $icon, $color, $type]);
            $created++;
        }
    }

    if ($canMark) {
        $db->prepare("INSERT INTO user_workspace_seeds (user_id, workspace) VALUES (?, 'business')")->execute([$userId]);
    }
    return $created;
}

/** Vuelve a permitir la siembra (al restablecer un espacio, para que recupere sus categorías base). */
function ws_clear_seed(PDO $db, $userId, $workspace) {
    try {
        $db->prepare("DELETE FROM user_workspace_seeds WHERE user_id = ? AND workspace = ?")->execute([$userId, ws_norm($workspace)]);
    } catch (PDOException $e) {
        // Sin tabla de siembra no hay nada que limpiar.
    }
}

/**
 * La categoría equivalente de $category en otro espacio, para el usuario:
 * la suya o de sistema con el mismo nombre y tipo si existe y, si no, una
 * copia propia (mismo nombre, ícono, color y tipo). Devuelve su id.
 */
function ws_category_twin(PDO $db, array $category, $targetWorkspace, $userId) {
    $target = ws_norm($targetWorkspace);
    $stmt = $db->prepare("
        SELECT id FROM categories
        WHERE LOWER(name) = LOWER(?) AND type = ? AND " . ws_sql('workspace') . " = ?
          AND (user_id IS NULL OR user_id = ?)
        ORDER BY CASE WHEN user_id IS NULL THEN 1 ELSE 0 END, id
        LIMIT 1
    ");
    $stmt->execute([$category['name'], $category['type'], $target, $userId]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int) $existing;
    }

    $db->prepare("INSERT INTO categories (user_id, name, icon, color, type, workspace) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$userId, $category['name'], $category['icon'] ?? 'fa-tag', $category['color'] ?? '#cccccc', $category['type'], $target]);
    return (int) $db->lastInsertId();
}

/**
 * Movimientos y presupuestos de un espacio que apuntan a una categoría del
 * OTRO espacio (el negocio usando "Alimentación" personal, o el cobro del
 * 4x1000 que buscaba la categoría por nombre sin mirar el espacio) se pasan
 * a la categoría equivalente de su propio espacio. Devuelve cuántas filas movió.
 */
function ws_fix_cross_workspace_categories(PDO $db) {
    $moved = 0;
    foreach (['transactions', 'budgets'] as $table) {
        $rows = $db->query("
            SELECT t.id, t.user_id, t.category_id, " . ws_sql('t.workspace') . " AS ws,
                   c.name, c.icon, c.color, c.type
            FROM {$table} t
            JOIN categories c ON c.id = t.category_id
            WHERE " . ws_sql('t.workspace') . " <> " . ws_sql('c.workspace') . "
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmtUpdate = $db->prepare("UPDATE {$table} SET category_id = ? WHERE id = ?");
        foreach ($rows as $row) {
            if (!in_array($row['ws'], ['personal', 'business'], true)) continue;
            $twin = ws_category_twin($db, $row, $row['ws'], (int) $row['user_id']);
            if ($twin === (int) $row['category_id']) continue;
            $stmtUpdate->execute([$twin, $row['id']]);
            ws_log_fix($db, $table, $row['id'], 'category_id', $row['category_id'], $twin);
            $moved++;
        }
    }
    return $moved;
}

/**
 * Los clientes de préstamos no guardaban el espacio (todos quedaron como
 * 'personal', también los creados desde el negocio). Se infiere de sus
 * préstamos: si todos son de un espacio, el cliente pasa a ese espacio; si
 * tiene préstamos en los dos, se le crea una copia para el otro espacio y se
 * le reasignan esos préstamos. Un cliente sin préstamos no tiene de dónde
 * inferirse y se queda como está. Devuelve ['moved' => n, 'split' => n].
 */
function ws_assign_client_workspaces(PDO $db) {
    $moved = 0;
    $split = 0;
    $clients = $db->query("SELECT id, user_id, name, document, phone, email, address, workspace FROM loan_clients")->fetchAll(PDO::FETCH_ASSOC);
    $stmtLoans = $db->prepare("SELECT DISTINCT " . ws_sql('workspace') . " AS ws FROM loans WHERE client_id = ?");

    foreach ($clients as $client) {
        $stmtLoans->execute([$client['id']]);
        $spaces = array_map('ws_norm', array_column($stmtLoans->fetchAll(PDO::FETCH_ASSOC), 'ws'));
        if (empty($spaces)) continue;

        $current = ws_norm($client['workspace']);
        $hasBusiness = in_array('business', $spaces, true);
        $hasPersonal = in_array('personal', $spaces, true);

        if ($hasBusiness xor $hasPersonal) {
            $target = $hasBusiness ? 'business' : 'personal';
            if ($current !== $target) {
                $db->prepare("UPDATE loan_clients SET workspace = ? WHERE id = ?")->execute([$target, $client['id']]);
                ws_log_fix($db, 'loan_clients', $client['id'], 'workspace', $current, $target);
                $moved++;
            }
            continue;
        }

        $other = $current === 'business' ? 'personal' : 'business';
        $db->prepare("INSERT INTO loan_clients (user_id, name, document, phone, email, address, workspace) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$client['user_id'], $client['name'], $client['document'], $client['phone'], $client['email'], $client['address'], $other]);
        $copyId = (int) $db->lastInsertId();

        $stmtMove = $db->prepare("SELECT id FROM loans WHERE client_id = ? AND " . ws_sql('workspace') . " = ?");
        $stmtMove->execute([$client['id'], $other]);
        $stmtRepoint = $db->prepare("UPDATE loans SET client_id = ? WHERE id = ?");
        foreach ($stmtMove->fetchAll(PDO::FETCH_COLUMN) as $loanId) {
            $stmtRepoint->execute([$copyId, $loanId]);
            ws_log_fix($db, 'loans', $loanId, 'client_id', $client['id'], $copyId);
        }
        $split++;
    }
    return ['moved' => $moved, 'split' => $split];
}

/**
 * Categoría "Gastos Bancarios" del espacio activo, para el cobro del 4x1000.
 * Antes se buscaba solo por nombre, así que el negocio cargaba ese impuesto
 * en la categoría del espacio personal. null si no existe.
 */
function ws_bank_fees_category_id(PDO $db, $userId, $workspace) {
    $space = ws_norm($workspace);
    if ($space === 'business') {
        ws_seed_business_categories($db, $userId);
    }
    $stmt = $db->prepare("
        SELECT id FROM categories
        WHERE name = 'Gastos Bancarios' AND (user_id IS NULL OR user_id = ?) AND " . ws_sql('workspace') . " = ?
        ORDER BY CASE WHEN user_id IS NULL THEN 1 ELSE 0 END, id
        LIMIT 1
    ");
    $stmt->execute([$userId, $space]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}
