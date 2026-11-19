<?php
// C:\laragon\www\control-finanzas\backend\api\migrate_workspaces.php
// Solo deploy.php lo invoca (por CLI, vía run_cmd) justo después de cada
// despliegue. No necesita quedar accesible por HTTP sin autenticación, así
// que se bloquea cualquier ejecución que no venga de la línea de comandos.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["error" => "Este script solo puede ejecutarse desde el proceso de despliegue."]);
    exit();
}

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/migrations.php';
require_once __DIR__ . '/../lib/workspace_data.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getConnection();
    ensure_migrations_table($db);

    // Para agregar un nuevo cambio de esquema en el futuro: envolverlo en
    // "if (!migration_done($db, 'nombre_unico')) { ...; mark_migration_done($db, 'nombre_unico'); }"
    // así solo corre una vez contra la base de datos, sin importar cuántas
    // veces se vuelva a ejecutar este script en despliegues futuros.
    $tables = ['transactions', 'accounts', 'categories', 'loans', 'loan_clients', 'category_budgets', 'budgets', 'savings_goals', 'reminders'];
    $migrated = [];

    // Cada bloque se registra en schema_migrations la primera vez que corre
    // con éxito, así los despliegues siguientes lo saltan por completo en vez
    // de repetir SHOW COLUMNS por cada tabla en cada deploy.
    if (!migration_done($db, 'workspace_columns_v1')) {
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
        mark_migration_done($db, 'workspace_columns_v1');
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

    if (!migration_done($db, 'extra_columns_v1')) {
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
        mark_migration_done($db, 'extra_columns_v1');
    }

    // Modificar category_id para que sea NULLable en transactions
    if (!migration_done($db, 'transactions_category_id_nullable')) {
        try {
            $db->exec("ALTER TABLE transactions MODIFY category_id INT NULL DEFAULT NULL");
            $migrated[] = "transactions (category_id NULLable)";
            mark_migration_done($db, 'transactions_category_id_nullable');
        } catch (Exception $e) {}
    }

    // Asegurar existencia de la categoría por defecto 'Gastos Bancarios'
    if (!migration_done($db, 'category_gastos_bancarios')) {
        try {
            $stmtCheckG = $db->query("SELECT id FROM categories WHERE name = 'Gastos Bancarios' LIMIT 1");
            if (!$stmtCheckG->fetch()) {
                $db->exec("INSERT INTO categories (name, icon, color, type, is_default) VALUES ('Gastos Bancarios', 'fa-building-columns', '#64748b', 'egreso', 1)");
                $migrated[] = "category (Gastos Bancarios)";
            }
            mark_migration_done($db, 'category_gastos_bancarios');
        } catch (Exception $e) {}
    }

    // Verificar si existe la columna business_name en users
    if (!migration_done($db, 'users_business_name')) {
        $stmtUser = $db->prepare("SHOW COLUMNS FROM users LIKE 'business_name'");
        $stmtUser->execute();
        if (!$stmtUser->fetch()) {
            $db->exec("ALTER TABLE users ADD COLUMN business_name VARCHAR(100) NULL DEFAULT 'Mi Negocio'");
            $migrated[] = 'users (business_name)';
        }
        mark_migration_done($db, 'users_business_name');
    }

    // Verificar si existe la columna reminder_days_before en users
    if (!migration_done($db, 'users_reminder_days_before')) {
        $stmtRem = $db->prepare("SHOW COLUMNS FROM users LIKE 'reminder_days_before'");
        $stmtRem->execute();
        if (!$stmtRem->fetch()) {
            $db->exec("ALTER TABLE users ADD COLUMN reminder_days_before INT NOT NULL DEFAULT 5");
            $migrated[] = 'users (reminder_days_before)';
        }
        mark_migration_done($db, 'users_reminder_days_before');
    }

    // Verificar si existe la columna items_json en budgets
    if (!migration_done($db, 'budgets_items_json')) {
        $stmtItems = $db->prepare("SHOW COLUMNS FROM budgets LIKE 'items_json'");
        $stmtItems->execute();
        if (!$stmtItems->fetch()) {
            $db->exec("ALTER TABLE budgets ADD COLUMN items_json TEXT NULL");
            $migrated[] = 'budgets (items_json)';
        }
        mark_migration_done($db, 'budgets_items_json');
    }

    // Verificar si existe la columna last_login_at en users (para ver
    // actividad real de uso, no solo si el correo está verificado)
    if (!migration_done($db, 'users_last_login_at')) {
        $stmtLogin = $db->prepare("SHOW COLUMNS FROM users LIKE 'last_login_at'");
        $stmtLogin->execute();
        if (!$stmtLogin->fetch()) {
            $db->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL");
            $migrated[] = 'users (last_login_at)';
        }
        mark_migration_done($db, 'users_last_login_at');
    }

    // Tabla de control de intentos de login fallidos (protección contra
    // fuerza bruta): 5 intentos fallidos en 15 minutos bloquea el correo
    // por 15 minutos.
    if (!migration_done($db, 'login_attempts_table')) {
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(191) NOT NULL,
            attempts INT NOT NULL DEFAULT 1,
            first_attempt_at DATETIME NOT NULL,
            locked_until DATETIME NULL,
            UNIQUE KEY identifier_unique (identifier)
        ) ENGINE=InnoDB");
        $migrated[] = 'login_attempts (tabla nueva)';
        mark_migration_done($db, 'login_attempts_table');
    }

    // Tabla de overrides de prompts de IA editables desde el panel de admin
    // (ver backend/lib/ai_prompts.php). Solo guarda las filas que el admin
    // personalizó; si un prompt_key no tiene fila aquí, ai_prompt_get() usa
    // el default hardcodeado en AI_PROMPT_DEFAULTS.
    if (!migration_done($db, 'ai_prompts_table')) {
        $db->exec("CREATE TABLE IF NOT EXISTS ai_prompts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prompt_key VARCHAR(64) NOT NULL,
            prompt_text LONGTEXT NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY prompt_key_unique (prompt_key)
        ) ENGINE=InnoDB");
        $migrated[] = 'ai_prompts (tabla nueva)';
        mark_migration_done($db, 'ai_prompts_table');
    }

    // ---- Independencia entre los espacios Personal y Negocio ----
    // Tablas de apoyo: la bitácora de cada dato que se reasigna (para poder
    // revertirlo a mano) y la marca de que a un usuario ya se le sembraron
    // las categorías base del negocio.
    if (!migration_done($db, 'workspace_fix_tables_v1')) {
        $db->exec("CREATE TABLE IF NOT EXISTS workspace_fix_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(64) NOT NULL,
            row_id INT NOT NULL,
            column_name VARCHAR(64) NOT NULL,
            old_value VARCHAR(255) NULL,
            new_value VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $db->exec("CREATE TABLE IF NOT EXISTS user_workspace_seeds (
            user_id INT NOT NULL,
            workspace VARCHAR(20) NOT NULL,
            seeded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, workspace)
        ) ENGINE=InnoDB");
        $migrated[] = 'workspace_fix_log y user_workspace_seeds (tablas nuevas)';
        mark_migration_done($db, 'workspace_fix_tables_v1');
    }

    // Movimientos y presupuestos de un espacio que apuntan a una categoría
    // del otro pasan a la categoría equivalente de su propio espacio.
    // Idempotente: cada cambio queda en workspace_fix_log.
    if (!migration_done($db, 'categories_cross_workspace_fix_v1')) {
        $db->beginTransaction();
        try {
            $moved = ws_fix_cross_workspace_categories($db);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $migrated[] = "categorías reasignadas a su espacio: {$moved} fila(s)";
        mark_migration_done($db, 'categories_cross_workspace_fix_v1');
    }

    // Los clientes de préstamos quedaron todos como 'personal': se infiere su
    // espacio de sus préstamos (ver ws_assign_client_workspaces).
    if (!migration_done($db, 'loan_clients_workspace_v1')) {
        $hasClients = $db->query("SHOW TABLES LIKE 'loan_clients'")->fetch() && $db->query("SHOW TABLES LIKE 'loans'")->fetch();
        if ($hasClients) {
            $db->beginTransaction();
            try {
                $clients = ws_assign_client_workspaces($db);
                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            $migrated[] = "clientes de préstamos: {$clients['moved']} pasaron de espacio, {$clients['split']} se dividieron";
        }
        mark_migration_done($db, 'loan_clients_workspace_v1');
    }

    // Índices compuestos para las consultas que más se repiten (todas filtran
    // por usuario + espacio y casi siempre por fecha). Sin ellos MySQL recorre
    // todos los movimientos del usuario en cada pantalla, y se nota más cuanto
    // más historial hay. Idempotente: salta lo que ya existe o no aplica, y un
    // fallo en un índice no frena el despliegue.
    if (!migration_done($db, 'performance_indexes_v1')) {
        $wanted = [
            'transactions' => ['idx_tx_user_ws_date' => ['user_id', 'workspace', 'date']],
            'accounts'     => ['idx_acc_user_ws' => ['user_id', 'workspace']],
            'categories'   => ['idx_cat_user_ws' => ['user_id', 'workspace']],
            'budgets'      => ['idx_bud_user_ws' => ['user_id', 'workspace']],
            'reminders'    => ['idx_rem_user_ws' => ['user_id', 'workspace']],
        ];
        $created = 0;
        foreach ($wanted as $table => $indexes) {
            try {
                if (!$db->query("SHOW TABLES LIKE '{$table}'")->fetch()) continue;
                $cols = array_map('strtolower', $db->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN));
                $existing = array_column($db->query("SHOW INDEX FROM {$table}")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
                foreach ($indexes as $name => $indexCols) {
                    if (in_array($name, $existing, true)) continue;
                    if (array_diff($indexCols, $cols)) continue;
                    $db->exec("ALTER TABLE {$table} ADD INDEX {$name} (" . implode(', ', $indexCols) . ")");
                    $created++;
                }
            } catch (Exception $e) {
                error_log("performance_indexes_v1 ({$table}): " . $e->getMessage());
            }
        }
        $migrated[] = "índices de rendimiento: {$created} creados";
        mark_migration_done($db, 'performance_indexes_v1');
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
