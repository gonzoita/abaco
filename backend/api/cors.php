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

// Capturar cualquier error o excepción de PHP y retornarlo en formato JSON seguro
set_exception_handler(function ($e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(["error" => "Error Servidor: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
});
