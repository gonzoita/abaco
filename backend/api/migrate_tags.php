<?php
// C:\laragon\www\control-finanzas\backend\api\migrate_tags.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getConnection();
    $db->exec("ALTER TABLE transactions ADD COLUMN tags VARCHAR(255) NULL AFTER description;");
    echo json_encode(["status" => "success", "message" => "Columna 'tags' agregada correctamente a 'transactions'."]);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false || strpos($e->getMessage(), "42S21") !== false) {
        echo json_encode(["status" => "info", "message" => "La columna 'tags' ya existía en la base de datos."]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error de migración: " . $e->getMessage()]);
    }
}
