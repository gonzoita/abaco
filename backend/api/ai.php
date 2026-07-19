<?php
// C:\laragon\www\control-finanzas\backend\api\ai.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Obtener API Key de cabeceras de la petición (para llaves personalizadas)
$userApiKey = '';
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (isset($headers['X-Gemini-API-Key'])) {
    $userApiKey = trim($headers['X-Gemini-API-Key']);
} elseif (isset($_SERVER['HTTP_X_GEMINI_API_KEY'])) {
    $userApiKey = trim($_SERVER['HTTP_X_GEMINI_API_KEY']);
}

$apiKeyToUse = !empty($userApiKey) ? $userApiKey : (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');

if (empty($apiKeyToUse)) {
    http_response_code(400);
    echo json_encode([
        "error" => "Clave de API de Gemini no configurada.",
        "details" => "Por favor, ingresa tu propia API Key en los Ajustes del sistema (Ajustes de IA) para poder usar las funciones inteligentes."
    ]);
    exit();
}

/**
 * Función auxiliar para realizar peticiones HTTP a la API de Gemini
 */
function callGemini($payload, $apiKey) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . $apiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    // Evitar errores SSL comunes en entornos de desarrollo local en Windows
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception("Error al conectar con la API de Gemini: " . curl_error($ch));
    }

    curl_close($ch);
    return json_decode($response, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. ESCANEAR RECIBO (OCR + Categorización)
    if ($action === 'scan_receipt') {
        $imageData = null;
        $mimeType = 'image/jpeg';

        // Manejar subida de archivo tradicional
        if (isset($_FILES['receipt'])) {
            $fileTmpPath = $_FILES['receipt']['tmp_name'];
            $mimeType = $_FILES['receipt']['type'];
            $imageData = base64_encode(file_get_contents($fileTmpPath));
        } else {
            // Alternativa: Recibir base64 directo en JSON
            $input = json_decode(file_get_contents('php://input'), true);
            if (isset($input['image'])) {
                // Limpiar prefijo base64 si viene incluido (data:image/png;base64,...)
                $rawImage = $input['image'];
                if (preg_match('/^data:(image\/[a-zA-Z]+);base64,(.+)$/', $rawImage, $matches)) {
                    $mimeType = $matches[1];
                    $imageData = $matches[2];
                } else {
                    $imageData = $rawImage;
                }
            }
        }

        if (!$imageData) {
            http_response_code(400);
            echo json_encode(["error" => "No se proporcionó ninguna imagen del recibo."]);
            exit();
        }

        // Construir prompt para estructurar la salida en JSON
        $prompt = "Analiza esta imagen de recibo de compra. Extrae y devuelve estrictamente un objeto JSON con los siguientes campos: "
                . "'comercio' (nombre del local o establecimiento, string), "
                . "'fecha' (fecha de compra en formato YYYY-MM-DD, string, si no se encuentra pon la fecha de hoy), "
                . "'monto' (el total pagado de la compra como número, sin símbolos de moneda ni comas de miles), "
                . "'categoria_sugerida' (debe ser estrictamente una de estas categorías: Alimentación, Vivienda, Transporte, Salud, Entretenimiento, Servicios Públicos, Educación, Compras, Inversiones, Otros), "
                . "'descripcion' (resumen corto de los artículos comprados, string)."
                . "No incluyas explicaciones adicionales, texto introductorio, ni bloques de código de markdown. Devuelve solo el JSON puro.";

        $payload = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt],
                        [
                            "inlineData" => [
                                "mimeType" => $mimeType,
                                "data" => $imageData
                            ]
                        ]
                    ]
                ]
            ]
        ];

        try {
            $result = callGemini($payload, $apiKeyToUse);
            
            if (isset($result['error'])) {
                $googleError = $result['error']['message'] ?? 'Error de la API de Google.';
                throw new Exception("Google API Error: " . $googleError);
            }
            
            // Extraer respuesta del texto
            $jsonText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            if (empty($jsonText)) {
                throw new Exception("Gemini no devolvió texto analizable.");
            }

            echo $jsonText; // Ya es un JSON válido retornado por el modelo

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al analizar el recibo: " . $e->getMessage()]);
        }
        exit();
    }

    // 2. CONSEJOS DE IA (Asistente de Chat)
    if ($action === 'get_advice') {
        $input = json_decode(file_get_contents('php://input'), true);
        $message = trim($input['message'] ?? '');

        if (empty($message)) {
            http_response_code(400);
            echo json_encode(["error" => "Debe proporcionar una consulta o pregunta."]);
            exit();
        }

        // Obtener resumen de las finanzas del usuario (para contextualizar)
        // 1. Balance por tipo de cuenta
        $stmtAccounts = $db->prepare("SELECT name, type, balance, currency FROM accounts WHERE user_id = ?");
        $stmtAccounts->execute([$userId]);
        $accounts = $stmtAccounts->fetchAll();
        
        // 2. Transacciones recientes
        $stmtTx = $db->prepare("SELECT t.type, t.amount, t.description, t.date, c.name as category 
                                FROM transactions t 
                                LEFT JOIN categories c ON t.category_id = c.id 
                                WHERE t.user_id = ? 
                                ORDER BY t.date DESC LIMIT 10");
        $stmtTx->execute([$userId]);
        $recentTransactions = $stmtTx->fetchAll();

        // Estructurar el resumen
        $summary = "Cuentas del usuario:\n";
        foreach ($accounts as $acc) {
            $summary .= "- {$acc['name']} ({$acc['type']}): {$acc['balance']} {$acc['currency']}\n";
        }
        $summary .= "\nÚltimos movimientos:\n";
        foreach ($recentTransactions as $tx) {
            $summary .= "- {$tx['date']} | {$tx['type']} | {$tx['amount']} | {$tx['description']} ({$tx['category']})\n";
        }

        $systemPrompt = "Eres 'Abaco', un asesor financiero personal interactivo integrado en una aplicación de control de gastos para Latinoamérica. "
                      . "Tu tono debe ser profesional, cercano, motivador y directo. Ayuda a planificar presupuestos, dar consejos sobre deudas y ahorro.\n"
                      . "Aquí están las finanzas actuales del usuario:\n"
                      . $summary . "\n"
                      . "Responde la pregunta del usuario basándose en este contexto (si aplica). Mantén tu respuesta concisa (máximo 3 párrafos), con sugerencias muy claras en pesos colombianos u otra moneda del usuario. Responde siempre en español.";

        $payload = [
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [
                        ["text" => $systemPrompt . "\n\nPregunta del usuario: " . $message]
                    ]
                ]
            ]
        ];

        try {
            $result = callGemini($payload, $apiKeyToUse);
            
            if (isset($result['error'])) {
                $googleError = $result['error']['message'] ?? 'Error de la API de Google.';
                echo json_encode(["response" => "⚠️ Error de Google Gemini: " . $googleError]);
                exit();
            }
            
            $responseText = $result['candidates'][0]['content']['parts'][0]['text'] ?? 'No pude procesar la consulta en este momento.';

            echo json_encode(["response" => $responseText]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al obtener consejo de la IA: " . $e->getMessage()]);
        }
        exit();
    }

    // 3. OPTIMIZAR PRESUPUESTO CON IA
    if ($action === 'optimize_budget') {
        // Obtener presupuestos de este mes
        $stmtBudgets = $db->prepare("
            SELECT b.amount, b.category_id, c.name as category_name
            FROM budgets b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.user_id = ? AND b.month = MONTH(CURRENT_DATE()) AND b.year = YEAR(CURRENT_DATE())
        ");
        $stmtBudgets->execute([$userId]);
        $budgets = $stmtBudgets->fetchAll();

        // Obtener gastos de este mes
        $stmtSpent = $db->prepare("
            SELECT category_id, SUM(amount) as spent 
            FROM transactions 
            WHERE user_id = ? AND type = 'egreso' AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())
            GROUP BY category_id
        ");
        $stmtSpent->execute([$userId]);
        $spents = $stmtSpent->fetchAll();

        $spentMap = [];
        foreach ($spents as $row) {
            $catId = $row['category_id'] !== null ? intval($row['category_id']) : 0;
            $spentMap[$catId] = floatval($row['spent']);
        }

        // Formatear el contexto para la IA
        $context = "Historial de Presupuestos vs Gastos de este mes:\n";
        foreach ($budgets as $b) {
            $catId = $b['category_id'] !== null ? intval($b['category_id']) : 0;
            $name = $b['category_name'] ?: 'Presupuesto Global';
            $limit = floatval($b['amount']);
            $spent = isset($spentMap[$catId]) ? $spentMap[$catId] : 0.00;
            $context .= "- {$name} (ID de Categoría: " . ($b['category_id'] ?: 'null') . "): Límite: {$limit} | Gastado: {$spent}\n";
        }

        $prompt = "Eres un consultor financiero inteligente de Antigravity Finanzas. Analiza estos datos:\n\n"
                . $context . "\n"
                . "Genera una propuesta de reajuste y optimización para el presupuesto de este usuario.\n"
                . "Devuelve estrictamente un objeto JSON con los siguientes dos campos:\n"
                . "1. 'recommendations' (string): Un análisis y consejo detallado en español (formato markdown) indicando qué categorías están en peligro, qué recortes recomiendas y consejos para ahorrar.\n"
                . "2. 'proposed_budgets' (array): Una lista de objetos con la propuesta de nuevos límites presupuestados para reajustar. Cada objeto debe tener 'category_id' (número de ID de categoría o null para el presupuesto global) y 'amount' (el nuevo monto recomendado como número).\n\n"
                . "El JSON devuelto debe ser válido y seguir esa estructura exacta. No agregues textos explicativos fuera de este objeto.";

        $payload = [
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [
                        ["text" => $prompt]
                    ]
                ]
            ]
        ];

        try {
            $result = callGemini($payload, $apiKeyToUse);
            
            if (isset($result['error'])) {
                $googleError = $result['error']['message'] ?? 'Error de la API de Google.';
                throw new Exception("Google API Error: " . $googleError);
            }
            $jsonText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            if (empty($jsonText)) {
                throw new Exception("La IA no devolvió una respuesta estructurada.");
            }

            echo $jsonText; // Ya es el JSON limpio devuelto por la IA
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al optimizar presupuesto: " . $e->getMessage()]);
        }
        exit();
    }
}

http_response_code(404);
echo json_encode(["error" => "Acción de IA no encontrada."]);
