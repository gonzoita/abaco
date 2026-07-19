<?php
// make_admin.php
require_once __DIR__ . '/backend/config/database.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    $db = Database::getConnection();
    echo "DB Connection: OK\n";
    
    // Asegurar que la columna role existe
    try {
        $db->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'user'");
        echo "Columna 'role' añadida a la tabla 'users'.\n";
    } catch (Exception $e) {
        // Ignorar si la columna ya existe
        echo "La columna 'role' ya existe o no se pudo agregar: " . $e->getMessage() . "\n";
    }
    
    // Actualizar el rol del usuario diegoblackout@gmail.com a 'admin'
    $email = 'diegoblackout@gmail.com';
    $stmt = $db->prepare("UPDATE users SET role = 'admin' WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() > 0) {
        echo "¡Éxito! El usuario '$email' ahora tiene el rol de 'admin'.\n";
    } else {
        echo "El usuario '$email' ya es admin o no se encontró en la base de datos (inicia sesión con Google una vez antes de correr este script).\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
