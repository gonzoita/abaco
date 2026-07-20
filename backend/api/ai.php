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
 * Función auxiliar para realizar peticiones HTTP a la API de Gemini con reintento y modelos de respaldo
 */
function callGemini($payload, $apiKey) {
    $models = [
        'gemini-1.5-flash',
        'gemini-2.0-flash',
        'gemini-2.5-flash',
        'gemini-flash-latest',
        'gemini-1.5-pro'
    ];

    $lastError = null;
    $lastResult = null;

    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $lastError = $curlErr;
            continue;
        }

        $decoded = json_decode($response, true);
        
        if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            return $decoded;
        }

        if (isset($decoded['error'])) {
            $errMsg = mb_strtolower($decoded['error']['message'] ?? '');
            $lastResult = $decoded;
            // Si el modelo específico está saturado o da error de demanda alta, reintentar con el siguiente modelo
            if (strpos($errMsg, 'high demand') !== false || strpos($errMsg, 'quota') !== false || strpos($errMsg, 'resource_exhausted') !== false || strpos($errMsg, '503') !== false || strpos($errMsg, 'overloaded') !== false) {
                continue;
            }
            return $decoded;
        }

        $lastResult = $decoded;
    }

    return $lastResult ?? ["error" => ["message" => "Google Gemini está experimentando alta demanda momentánea. Por favor intenta de nuevo en unos segundos."]];
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
        $accounts = [];
        $recentTransactions = [];
        $loans = [];

        try {
            $stmtAccounts = $db->prepare("SELECT name, type, balance, currency FROM accounts WHERE user_id = ?");
            $stmtAccounts->execute([$userId]);
            $accounts = $stmtAccounts->fetchAll();
        } catch (Exception $e) {}
        
        try {
            $stmtTx = $db->prepare("SELECT t.type, t.amount, t.description, t.date, c.name as category 
                                    FROM transactions t 
                                    LEFT JOIN categories c ON t.category_id = c.id 
                                    WHERE t.user_id = ? 
                                    ORDER BY t.date DESC LIMIT 10");
            $stmtTx->execute([$userId]);
            $recentTransactions = $stmtTx->fetchAll();
        } catch (Exception $e) {}

        try {
            $stmtLoans = $db->prepare("SELECT l.amount, l.type, c.name as person_name 
                                       FROM loans l 
                                       LEFT JOIN loan_clients c ON l.client_id = c.id 
                                       WHERE l.user_id = ? AND l.status != 'finalizado'");
            $stmtLoans->execute([$userId]);
            $loans = $stmtLoans->fetchAll();
        } catch (Exception $e) {}

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
                $summary .= "- Préstamo a/de {$l['person_name']}: Monto inicial {$l['amount']}\n";
            }
        }

        $systemPrompt = "Eres 'Ábaco', el asesor financiero personal inteligente, mentor de ahorro, guía de inversión y tutor interactivo oficial de la aplicación Ábaco.\n"
                      . "Tu tono es inspirador, sabio, profesional, cercano y muy práctico. Tu misión principal es enseñar a las personas a ahorrar más dinero, invertir de forma inteligente, multiplicar sus ingresos y dominar al 100% todas las herramientas de la aplicación.\n\n"
                      . "PRINCIPIOS DE AHORRO E INVERSIÓN QUE DEBES ENSEÑAR (Habla como tu propio conocimiento de experto, sin citar libros ni nombres de autores):\n"
                      . "1. La Regla del Ahorro Sagrado (Págate a ti mismo primero): Antes de pagar cualquier factura o gasto, separa de forma inamovible al menos el 10% de todo lo que ingrese a tus manos y guárdalo en una cuenta de reserva.\n"
                      . "2. Control Estratégico de Gastos vs Inversión en Activos: Diferencia siempre entre un Activo (algo que pone dinero en tu bolsillo de forma recurrente) y un Pasivo (algo que saca dinero de tu bolsillo). Elimina los gastos hormiga que no generan valor.\n"
                      . "3. Expansión de Ingresos y Multiplicación: No te limites únicamente a recortar gastos. Busca activamente crear múltiples fuentes de ingresos, invertir en activos productivos y escalar tu patrimonio con disciplina constante.\n"
                      . "4. Protección del Capital y Fondo de Emergencia: Mantén siempre entre 3 a 6 meses de gastos en tu fondo de autonomía antes de asumir riesgos de inversión altos.\n\n"
                      . "TUTORIAL PASO A PASO DE LAS HERRAMIENTAS DE ÁBACO (Explica con claridad a los usuarios cómo utilizarlas cuando pregunten):\n"
                      . "- Score de Salud Financiera (0 a 100): Se ubica en la parte superior del Dashboard. Evalúa automáticamente tu porcentaje de ahorro, tus meses de reserva de emergencia, tu disciplina con los presupuestos y tu nivel de deudas. Te indica si estás en nivel Excelente, Saludable o En Riesgo y qué hacer para subir tu puntaje.\n"
                      . "- Autonomía Financiera & Fondo de Reserva: Te indica exactamente cuántos meses y días podrías vivir si tus ingresos se detuvieran hoy. Además, calcula una Predicción de Cierre de Mes para avisarte si terminarás con ahorro o con déficit.\n"
                      . "- Generación de Reportes Ejecutivos en PDF & Excel: En el Dashboard o en la sección de analítica puedes tocar el botón 'Reporte PDF' para abrir un informe completo y formal listo para guardar e imprimir, o 'Excel/CSV' para descargar el archivo de datos para hojas de cálculo.\n"
                      . "- Etiquetas Personalizadas (#Tags): Al registrar o editar cualquier ingreso o gasto, puedes escribir etiquetas como #Viaje, #Vacaciones, #Proyecto o #Negocio para agrupar movimientos de un evento sin alterar tus categorías habituales.\n"
                      . "- Módulo de Préstamos: Ideal para cuando le prestas dinero a personas ('Por Cobrar') o tienes compromisos 'Por Pagar'. Puedes añadir clientes o deudores, registrar abonos parciales y ver el saldo pendiente actualizado automáticamente.\n"
                      . "- Escáner de Recibos con IA & Presupuestos: Al presionar el icono de la cámara, la IA lee la foto de tu recibo físico y llena el formulario automáticamente. En Presupuestos puedes fijar topes mensuales por categoría.\n\n"
                      . "Aquí está el resumen del estado financiero actual del usuario:\n"
                      . $summary . "\n"
                      . "INSTRUCCIÓN DE RESPUESTA: Responde con empatía, brindando consejos de ahorro e inversión claros y concretos (sin nombrar libros ni fuentes externas), y guiando paso a paso sobre el uso de la app. Mantén las respuestas fluidas y motivadoras (máximo 3-4 párrafos) en español.";

        $contextualHistory = "";
        if (!empty($historyInput)) {
            $contextualHistory .= "\n[ HISTORIAL RECIENTE DE CONVERSACIÓN CON EL USUARIO ]:\n";
            foreach ($historyInput as $hMsg) {
                $senderName = (isset($hMsg['sender']) && $hMsg['sender'] === 'user') ? 'Usuario' : 'Ábaco (Tú)';
                $text = trim($hMsg['text'] ?? '');
                if (!empty($text)) {
                    $contextualHistory .= "- {$senderName}: {$text}\n";
                }
            }
        }

        $fullPrompt = $systemPrompt . $contextualHistory . "\n[ PREGUNTA ACTUAL DEL USUARIO ]: " . $message;

        $payload = [
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [
                        ["text" => $fullPrompt]
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
