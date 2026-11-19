<?php
// backend/lib/backup_logic.php
//
// Exportar, cargar y restablecer datos ahora actúan sobre UN espacio de
// trabajo (el activo), no sobre toda la cuenta: antes "Restablecer" borraba
// también lo del otro espacio y dejaba las cuentas nuevas como personales.
// Funciones puras sobre PDO para poder probarlas con SQLite.

require_once __DIR__ . '/workspace_data.php';

/** Tablas del respaldo y qué filtro de dueño usa cada una. */
const BACKUP_TABLES = ['categories', 'accounts', 'transactions', 'budgets', 'savings_goals', 'reminders'];

/** Cuentas que se crean al restablecer un espacio (mismos nombres que usa accounts.php al sembrar el negocio). */
const BACKUP_DEFAULT_ACCOUNTS = [
    'personal' => [['Efectivo', 'efectivo', 0], ['Mi Cuenta de Ahorros', 'banco', 1]],
    'business' => [['Caja Chica Negocio', 'efectivo', 0], ['Cuenta Banco Empresa', 'banco', 0]],
];

/**
 * Datos del usuario en UN espacio. 'categories' trae solo las categorías
 * propias de ese espacio (las de sistema existen siempre y no se respaldan).
 */
function backup_export(PDO $db, $userId, $workspace) {
    $space = ws_norm($workspace);
    $where = "user_id = ? AND " . ws_sql('workspace') . " = ?";

    $stmtUser = $db->prepare("SELECT id, name, email, currency, reminder_days_before FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);

    $backup = [
        'export_date' => date('Y-m-d H:i:s'),
        'backup_version' => 2,
        'workspace' => $space,
        'user' => $stmtUser->fetch(PDO::FETCH_ASSOC),
    ];
    foreach (BACKUP_TABLES as $table) {
        $order = $table === 'transactions' ? ' ORDER BY date DESC' : '';
        $stmt = $db->prepare("SELECT * FROM {$table} WHERE {$where}{$order}");
        $stmt->execute([$userId, $space]);
        $backup[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $backup;
}

/**
 * Deja del respaldo solo lo que corresponde al espacio activo.
 *  - Un respaldo del formato nuevo declara su espacio: si no es el activo se
 *    rechaza con un mensaje que dice a cuál cambiar (error), en vez de
 *    mezclar datos de un espacio dentro del otro.
 *  - Un respaldo antiguo trae los dos espacios revueltos: se cargan solo las
 *    filas del espacio activo y se cuenta cuántas se dejaron fuera.
 * Devuelve ['backup' => ..., 'ignored' => n] o ['error' => '...'].
 */
function backup_prepare_import(array $backup, $workspace) {
    $space = ws_norm($workspace);
    $names = ['personal' => 'Personal', 'business' => 'Negocio'];

    if (isset($backup['workspace'])) {
        $declared = ws_norm($backup['workspace']);
        if ($declared !== $space) {
            return ['error' => "Este respaldo es del espacio {$names[$declared]} y estás en {$names[$space]}. Cambia a {$names[$declared]} (arriba a la izquierda) y vuelve a cargarlo."];
        }
    }

    $ignored = 0;
    foreach (BACKUP_TABLES as $table) {
        if (!isset($backup[$table]) || !is_array($backup[$table])) continue;
        $kept = [];
        foreach ($backup[$table] as $row) {
            if (!is_array($row) || ws_norm($row['workspace'] ?? null) !== $space) {
                $ignored++;
                continue;
            }
            $row['workspace'] = $space;
            $kept[] = $row;
        }
        $backup[$table] = $kept;
    }
    return ['backup' => $backup, 'ignored' => $ignored];
}

/**
 * Borra los datos de UN espacio y recrea sus cuentas por defecto. El otro
 * espacio no se toca. Las categorías de sistema (user_id NULL) tampoco, y se
 * habilita de nuevo la siembra de las categorías base del negocio.
 */
function backup_reset_workspace(PDO $db, $userId, $workspace) {
    $space = ws_norm($workspace);
    $where = "user_id = ? AND " . ws_sql('workspace') . " = ?";

    foreach (['transactions', 'budgets', 'savings_goals', 'reminders', 'accounts', 'categories'] as $table) {
        $db->prepare("DELETE FROM {$table} WHERE {$where}")->execute([$userId, $space]);
    }
    ws_clear_seed($db, $userId, $space);

    $stmtUser = $db->prepare("SELECT currency FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $currency = $stmtUser->fetchColumn() ?: 'COP';

    $stmtAccount = $db->prepare("INSERT INTO accounts (user_id, name, type, balance, currency, tax_exempt, workspace) VALUES (?, ?, ?, 0, ?, ?, ?)");
    foreach (BACKUP_DEFAULT_ACCOUNTS[$space] as [$name, $type, $taxExempt]) {
        $stmtAccount->execute([$userId, $name, $type, $currency, $taxExempt, $space]);
    }
}
