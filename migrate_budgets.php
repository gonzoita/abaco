<?php
// migrate_budgets.php
require_once __DIR__ . '/backend/config/database.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    $db = Database::getConnection();
    echo "DB Connection: OK\n";
    
    // Crear la tabla de presupuestos
    $db->exec("CREATE TABLE IF NOT EXISTS budgets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category_id INT NULL,
        amount DECIMAL(15, 2) NOT NULL,
        month INT NOT NULL,
        year INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");
    
    echo "Exito: La tabla 'budgets' fue creada correctamente en la base de datos!\n";
} catch (Exception $e) {
    echo "Error en la migracion: " . $e->getMessage() . "\n";
}
