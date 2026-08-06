<?php
// C:\laragon\www\control-finanzas\backend\api\cors.php

// Permitir solicitudes de cualquier origen para facilitar el desarrollo local y PWA
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-Gemini-API-Key, X-Workspace");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Manejar preflight request (OPTIONS) para peticiones complejas
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Capturar cualquier error o excepción de PHP, dejar rastro en un log local
// (para poder revisar qué falló sin depender de que el usuario avise) y
// retornarlo en formato JSON seguro sin exponer detalles internos al cliente.
set_exception_handler(function ($e) {
    $logLine = sprintf(
        "[%s] %s %s -> %s: %s in %s:%d\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        $_SERVER['REQUEST_URI'] ?? basename(__FILE__),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents($logDir . '/error.log', $logLine, FILE_APPEND);

    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(["error" => "Error Servidor: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
});
