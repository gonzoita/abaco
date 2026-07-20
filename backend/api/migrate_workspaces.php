<?php
// C:\laragon\www\control-finanzas\backend\api\migrate_workspaces.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getConnection();
    
    $tables = ['transactions', 'accounts', 'categories', 'loans', 'category_budgets'];
    $migrated = [];

    foreach ($tables as $table) {
        // Verificar si existe la columna 'workspace'
        $stmt = $db->prepare("SHOW COLUMNS FROM {$table} LIKE 'workspace'");
        $stmt->execute();
        $columnExists = $stmt->fetch();

        if (!$columnExists) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN workspace VARCHAR(20) NOT NULL DEFAULT 'personal'");
            $migrated[] = $table;
        }
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
