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

        $historyInput = isset($input['history']) && is_array($input['history']) ? $input['history'] : [];
        
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

        // 3. Préstamos activos
        $stmtLoans = $db->prepare("SELECT person_name, type, amount, balance FROM loans WHERE user_id = ? AND status = 'active'");
        $stmtLoans->execute([$userId]);
        $loans = $stmtLoans->fetchAll();

        // Estructurar el resumen
        $summary = "Cuentas del usuario:\n";
        foreach ($accounts as $acc) {
            $summary .= "- {$acc['name']} ({$acc['type']}): {$acc['balance']} {$acc['currency']}\n";
        }
        $summary .= "\nÚltimos movimientos:\n";
        foreach ($recentTransactions as $tx) {
            $summary .= "- {$tx['date']} | {$tx['type']} | {$tx['amount']} | {$tx['description']} ({$tx['category']})\n";
        }
        if (!empty($loans)) {
            $summary .= "\nPréstamos registrados:\n";
            foreach ($loans as $l) {
                $summary .= "- " . ($l['type'] === 'por_cobrar' ? "Por Cobrar a " : "Por Pagar a ") . "{$l['person_name']}: Saldo {$l['balance']} (Monto inicial: {$l['amount']})\n";
            }
        }

        $systemPrompt = "Eres 'Ábaco', un asesor financiero personal interactivo, mentor de riqueza y tutor oficial de la aplicación de control de finanzas.\n"
                      . "Tu tono es profesional, cercano, directo, motivador y sabio. Tu misión es educar sobre el uso de la herramienta y guiar al usuario hacia la libertad financiera.\n\n"
                      . "FILOSOFÍA Y LIBROS DE RECOMENDACIÓN FINANCIERA DE ÁBACO:\n"
                      . "1. 'El Hombre Más Rico de Babilonia' (George S. Clason): Págate a ti mismo primero (guarda al menos el 10% de todo lo que ganes antes de pagar tus gastos). Controla tus gastos, haz que tu dinero trabaje para ti multiplicándose y protege tu capital de inversiones dudosas.\n"
                      . "2. 'El Folleto del Millonario / La Regla 10X' (Grant Cardone): Enfócate en multiplicar tus ingresos en lugar de solo recortar cafecitos. Establece metas 10 veces más grandes, mantén una disciplina implacable y busca múltiples fuentes de ingresos.\n"
                      . "3. 'Padre Rico, Padre Pobre' (Robert Kiyosaki): Entiende la diferencia entre un Activo (pone dinero en tu bolsillo) y un Pasivo (saca dinero de tu bolsillo). Adquiere bienes que generen flujo de caja.\n\n"
                      . "TUTORIAL Y FUNCIONALIDADES DE ÁBACO:\n"
                      . "- Módulo de Préstamos (¡Importante cuando prestas dinero a personas!): Puedes registrar préstamos 'Por Cobrar' (dinero que prestaste a amigos, familiares o clientes) o 'Por Pagar' (deudas tuyas). Cada préstamo te permite llevar el historial de abonos parciales, calcular el saldo pendiente y marcar cuando el préstamo esté saldado por completo.\n"
                      . "- Dashboard: Balance total, gráfica interactiva de 'Distribución de Gastos' (al hacer clic en cualquier categoría del gráfico se filtran tus movimientos abajo), botones rápidos de Ingreso/Gasto y el 'Escáner de Recibos IA' (icono cámara) para leer facturas físicas.\n"
                      . "- Cuentas y Metas de Ahorro: Manejo de cuentas de efectivo, banco y tarjetas de crédito con corte/pago, más metas de ahorro con progreso.\n"
                      . "- Presupuestos: Establece límites mensuales por categoría y utiliza el 'Optimizador IA' para reajustar los límites según tus hábitos reales.\n"
                      . "- Ajustes: Configuración de perfil, cambio de moneda (COP, USD, MXN, EUR) y creador de categorías con más de 80 iconos de FontAwesome.\n\n"
                      . "Aquí está el estado financiero actual del usuario:\n"
                      . $summary . "\n"
                      . "INSTRUCCIÓN DE RESPUESTA: Responde con empatía, aplicando principios de los libros mencionados si aplica, y guiando paso a paso sobre las funciones de la app. Mantén las respuestas claras (máximo 3-4 párrafos) en español.";

        // Construir arreglo de contenidos con historial para mantener el contexto
        $contents = [];
        $contents[] = [
            "role" => "user",
            "parts" => [["text" => $systemPrompt]]
        ];
        $contents[] = [
            "role" => "model",
            "parts" => [["text" => "¡Hola! Entendido. Soy Ábaco, tu asesor y mentor financiero personal. Mantendré el contexto de nuestras conversaciones y estoy listo para ayudarte con tus finanzas y enseñarte a aprovechar al máximo todas las herramientas."]]
        ];

        // Agregar historial de la conversación previa
        foreach ($historyInput as $hMsg) {
            $role = ($hMsg['sender'] === 'user') ? 'user' : 'model';
            $text = trim($hMsg['text'] ?? '');
            if (!empty($text)) {
                $contents[] = [
                    "role" => $role,
                    "parts" => [["text" => $text]]
                ];
            }
        }

        // Agregar el mensaje actual del usuario
        $contents[] = [
            "role" => "user",
            "parts" => [["text" => $message]]
        ];

        $payload = [
            "contents" => $contents
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
