<?php
// C:\laragon\www\control-finanzas\backend\api\cors.php

// Permitir solicitudes de cualquier origen para facilitar el desarrollo local y PWA
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-Gemini-API-Key");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Manejar preflight request (OPTIONS) para peticiones complejas
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
