<?php
// C:\laragon\www\control-finanzas\backend\config\database.php

// Nunca mostrar errores/warnings de PHP en la respuesta (podrían filtrar
// rutas del servidor, consultas SQL o credenciales); sí queda registrado en
// el log del servidor para poder depurar. Este archivo lo cargan todos los
// endpoints del backend, así que endurece la configuración para toda la API.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Intentar cargar variables de entorno desde un archivo .env en varias ubicaciones posibles
$envPaths = [
    __DIR__ . '/../../.env',
    __DIR__ . '/../.env',
    __DIR__ . '/.env',
    dirname(__DIR__, 2) . '/.env',
    getcwd() . '/.env',
    getcwd() . '/../.env'
];

foreach ($envPaths as $envPath) {
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim(trim($value), "\"'");
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Configuración de la base de datos (con fallback a valores de Laragon)
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'control_finanzas');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''); // En Laragon la contraseña por defecto es vacía

// JWT Secret Key para tokens de sesión
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'clave_secreta_por_defecto_123456');

class Database {
    private static $connection = null;

    public static function getConnection() {
        if (self::$connection === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";port=" . DB_PORT . ";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                self::$connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Configurar cabecera JSON
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode(["error" => "Error de conexión a la base de datos: " . $e->getMessage()]);
                exit;
            }
        }
        return self::$connection;
    }
}
