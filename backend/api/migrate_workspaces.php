<?php
// C:\laragon\www\control-finanzas\backend\api\migrate_workspaces.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getConnection();
    
    $tables = ['transactions', 'accounts', 'categories', 'loans', 'loan_clients', 'category_budgets', 'budgets', 'savings_goals', 'reminders'];
    $migrated = [];

    foreach ($tables as $table) {
        // Verificar si la tabla existe antes de alterar
        $stmtTable = $db->query("SHOW TABLES LIKE '{$table}'");
        if ($stmtTable->fetch()) {
            // Verificar si existe la columna 'workspace'
            $stmt = $db->prepare("SHOW COLUMNS FROM {$table} LIKE 'workspace'");
            $stmt->execute();
            $columnExists = $stmt->fetch();

            if (!$columnExists) {
                $db->exec("ALTER TABLE {$table} ADD COLUMN workspace VARCHAR(20) NOT NULL DEFAULT 'personal'");
                $migrated[] = $table;
            }

            // Normalizar registros nulos o vacíos a 'personal'
            $db->exec("UPDATE {$table} SET workspace = 'personal' WHERE workspace IS NULL OR workspace = ''");
        }
    }

    // Definir columnas adicionales requeridas por cada tabla
    $extraColumns = [
        'transactions' => [
            'tags' => "VARCHAR(255) NULL",
            'receipt_url' => "VARCHAR(255) NULL",
            'installments_total' => "INT NOT NULL DEFAULT 1",
            'installments_current' => "INT NOT NULL DEFAULT 1",
            'transfer_to_account_id' => "INT NULL"
        ],
        'accounts' => [
            'bank_name' => "VARCHAR(100) NULL",
            'account_number' => "VARCHAR(50) NULL",
            'tax_exempt' => "TINYINT(1) NOT NULL DEFAULT 0",
            'credit_limit' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            'billing_day' => "INT NULL",
            'due_day' => "INT NULL",
            'interest_rate' => "VARCHAR(20) NULL",
            'term_months' => "INT NULL",
            'payment_conditions' => "TEXT NULL"
        ]
    ];

    foreach ($extraColumns as $tbl => $cols) {
        $stmtTable = $db->query("SHOW TABLES LIKE '{$tbl}'");
        if ($stmtTable->fetch()) {
            foreach ($cols as $colName => $colDef) {
                $stmtC = $db->prepare("SHOW COLUMNS FROM {$tbl} LIKE '{$colName}'");
                $stmtC->execute();
                if (!$stmtC->fetch()) {
                    $db->exec("ALTER TABLE {$tbl} ADD COLUMN {$colName} {$colDef}");
                    $migrated[] = "{$tbl} ({$colName})";
                }
            }
        }
    }

    // Verificar si existe la columna business_name en users
    $stmtUser = $db->prepare("SHOW COLUMNS FROM users LIKE 'business_name'");
    $stmtUser->execute();
    if (!$stmtUser->fetch()) {
        $db->exec("ALTER TABLE users ADD COLUMN business_name VARCHAR(100) NULL DEFAULT 'Mi Negocio'");
        $migrated[] = 'users (business_name)';
    }

    // Verificar si existe la columna reminder_days_before en users
    $stmtRem = $db->prepare("SHOW COLUMNS FROM users LIKE 'reminder_days_before'");
    $stmtRem->execute();
    if (!$stmtRem->fetch()) {
        $db->exec("ALTER TABLE users ADD COLUMN reminder_days_before INT NOT NULL DEFAULT 5");
        $migrated[] = 'users (reminder_days_before)';
    }

    // Verificar si existe la columna items_json en budgets
    $stmtItems = $db->prepare("SHOW COLUMNS FROM budgets LIKE 'items_json'");
    $stmtItems->execute();
    if (!$stmtItems->fetch()) {
        $db->exec("ALTER TABLE budgets ADD COLUMN items_json TEXT NULL");
        $migrated[] = 'budgets (items_json)';
    }

    echo json_encode([
        "success" => true,
        "message" => "Migración de espacios de trabajo (workspaces) completada con éxito.",
        "tables_updated" => $migrated
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Error al migrar tablas para workspaces: " . $e->getMessage()
    ]);
}
