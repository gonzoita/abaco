<?php
// list_models.php
require_once __DIR__ . '/backend/config/database.php';

header('Content-Type: text/plain; charset=UTF-8');

// Obtener la llave del server o por parámetro GET
$headers = function_exists('getallheaders') ? getallheaders() : [];
$userApiKey = '';
if (isset($headers['X-Gemini-API-Key'])) {
    $userApiKey = trim($headers['X-Gemini-API-Key']);
} elseif (isset($_SERVER['HTTP_X_GEMINI_API_KEY'])) {
    $userApiKey = trim($_SERVER['HTTP_X_GEMINI_API_KEY']);
}

$apiKey = !empty($userApiKey) ? $userApiKey : (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');

if (empty($apiKey)) {
    echo "Error: No hay API Key configurada en el servidor (GEMINI_API_KEY en .env está vacío).\n";
    exit();
}

echo "API Key (primeros 5 caracteres): " . substr($apiKey, 0, 5) . "...\n";

// Llamar a listModels
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo "Curl Error: " . curl_error($ch) . "\n";
} else {
    echo "HTTP Status Code: " . $httpCode . "\n";
    echo "Response:\n";
    
    $data = json_decode($response, true);
    if (isset($data['error'])) {
        print_r($data['error']);
    } else {
        // Listar los nombres de los modelos disponibles
        if (isset($data['models'])) {
            foreach ($data['models'] as $m) {
                echo "- " . $m['name'] . " (Soporta: " . implode(', ', $m['supportedGenerationMethods']) . ")\n";
            }
        } else {
            echo "No se encontraron modelos. Respuesta cruda:\n";
            echo $response . "\n";
        }
    }
}
curl_close($ch);
