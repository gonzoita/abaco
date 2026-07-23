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
 * Función auxiliar para realizar peticiones HTTP a la API de Gemini con reintentos para picos de demanda
 */
function callGemini($payload, $apiKey) {
    $models = ['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-1.5-flash-latest', 'gemini-flash-latest', 'gemini-1.5-flash'];
    $lastDecoded = null;

    foreach ($models as $modelName) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $modelName . ":generateContent?key=" . $apiKey;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);

            $response = curl_exec($ch);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                sleep(1);
                continue;
            }

            $decoded = json_decode($response, true);
            $lastDecoded = $decoded;

            // Éxito: devolvió texto válido
            if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
                return $decoded;
            }

            if (isset($decoded['error']['message'])) {
                $errMsg = mb_strtolower($decoded['error']['message']);
                if (strpos($errMsg, 'api_key_invalid') !== false || strpos($errMsg, 'invalid api key') !== false) {
                    return $decoded;
                }
                // Si el modelo no existe o no es soportado por esa clave/API, pasar al siguiente modelo
                if (strpos($errMsg, 'not found') !== false || strpos($errMsg, 'not supported') !== false) {
                    break; // romper reintentos del modelo actual y probar el siguiente en el foreach
                }
                if (strpos($errMsg, 'high demand') !== false || strpos($errMsg, 'overloaded') !== false || strpos($errMsg, '503') !== false || strpos($errMsg, 'resource_exhausted') !== false || strpos($errMsg, 'quota') !== false) {
                    sleep(1);
                    continue;
                }
            }
            break;
        }
    }

    return $lastDecoded ?? ["error" => ["message" => "Google Gemini no pudo procesar la solicitud. Verifica tu clave de API."]];
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

    // 2. DICTADO POR VOZ (Convertir voz del usuario a objeto de transacción)
    if ($action === 'voice_transaction') {
        $input = json_decode(file_get_contents('php://input'), true);
        $transcript = trim($input['transcript'] ?? '');

        if (empty($transcript)) {
            http_response_code(400);
            echo json_encode(["error" => "No se recibió ninguna transcripción de voz."]);
            exit();
        }

        // Obtener categorías y cuentas del usuario para mapear inteligentemente
        $categoriesList = [];
        $accountsList = [];
        try {
            $aWsCond = get_workspace_sql_clause('workspace');
            $stmtC = $db->prepare("SELECT id, name, type FROM categories WHERE user_id = ? OR is_default = 1");
            $stmtC->execute([$userId]);
            $categoriesList = $stmtC->fetchAll(PDO::FETCH_ASSOC);

            $stmtA = $db->prepare("SELECT id, name, type FROM accounts WHERE user_id = ? AND {$aWsCond}");
            $stmtA->execute([$userId]);
            $accountsList = $stmtA->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        $categoriesJson = json_encode($categoriesList, JSON_UNESCAPED_UNICODE);
        $accountsJson = json_encode($accountsList, JSON_UNESCAPED_UNICODE);
        $defaultAccId = !empty($accountsList) ? $accountsList[0]['id'] : null;

        $prompt = "Eres el motor de análisis de voz de la aplicación Ábaco. "
                . "El usuario acaba de dictar por micrófono: \"{$transcript}\".\n"
                . "Analiza la frase y devuelve estrictamente un objeto JSON con los siguientes campos:\n"
                . "- 'type': 'egreso' (si es gasto, pago, compra) o 'ingreso' (si es cobra, venta, abono, sueldo).\n"
                . "- 'amount': número entero positivo con el valor monetario mencionado (ej: 50 mil -> 50000, 120000 -> 120000). Si no hay monto pon 0.\n"
                . "- 'description': título o concepto del gasto (ej: 'Cine', 'Gasolina', 'Almuerzo'). Capitaliza la primera letra.\n"
                . "- 'category_name': el nombre de la categoría más adecuada (ej: Alimentación, Transporte, Entretenimiento, Salud, Servicios Públicos, Vivienda, Educación, Compras, Salario).\n"
                . "- 'category_id': ID entero de la categoría si coincide en este listado: {$categoriesJson}, o null si no existe.\n"
                . "- 'account_id': ID entero de la cuenta mencionada en este listado: {$accountsJson}. Si no menciona ninguna cuenta explícitamente, retorna el ID de la primera cuenta por defecto ({$defaultAccId}).\n"
                . "- 'tags': hashtags relevantes si aplica (ej: '#Cine', '#Gasolina').\n"
                . "No incluyas markdown, formato ni texto adicional. Devuelve solo el JSON puro.";

        $payload = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.1,
                "responseMimeType" => "application/json"
            ]
        ];

        try {
            $result = callGemini($payload, $apiKeyToUse);
            // Reintento de contingencia sin responseMimeType si falla
            if (isset($result['error'])) {
                unset($payload['generationConfig']['responseMimeType']);
                $result = callGemini($payload, $apiKeyToUse);
            }

            if (isset($result['error'])) {
                http_response_code(500);
                echo json_encode(["error" => $result['error']['message'] ?? 'Error al procesar voz con IA']);
                exit();
            }

            $rawText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            $cleanText = preg_replace('/```(?:json)?/i', '', $rawText);
            $cleanText = trim($cleanText);

            $parsedData = json_decode($cleanText, true);
            if (!$parsedData) {
                // Extraer el bloque JSON de la respuesta si vino rodeado de texto
                if (preg_match('/\{.*\}/s', $cleanText, $matches)) {
                    $parsedData = json_decode($matches[0], true);
                }
            }

            if (!$parsedData) {
                $parsedData = [
                    "type" => "egreso",
                    "amount" => 0,
                    "description" => $transcript,
                    "account_id" => $defaultAccId
                ];
            }

            if (empty($parsedData['account_id']) && $defaultAccId) {
                $parsedData['account_id'] = $defaultAccId;
            }

            echo json_encode($parsedData, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al interpretar dictado: " . $e->getMessage()]);
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
            $stmtAccounts = $db->prepare("SELECT name, type, balance, currency FROM accounts WHERE user_id = ? AND (workspace IS NULL OR workspace = ?)");
            $stmtAccounts->execute([$userId, $workspace]);
            $accounts = $stmtAccounts->fetchAll();
        } catch (Exception $e) {}
        
        try {
            $stmtTx = $db->prepare("SELECT t.type, t.amount, t.description, t.date, c.name as category 
                                    FROM transactions t 
                                    LEFT JOIN categories c ON t.category_id = c.id 
                                    WHERE t.user_id = ? AND (t.workspace IS NULL OR t.workspace = ?) 
                                    ORDER BY t.date DESC LIMIT 10");
            $stmtTx->execute([$userId, $workspace]);
            $recentTransactions = $stmtTx->fetchAll();
        } catch (Exception $e) {}

        try {
            $stmtLoans = $db->prepare("SELECT l.amount, l.type, c.name as person_name 
                                       FROM loans l 
                                       LEFT JOIN loan_clients c ON l.client_id = c.id 
                                       WHERE l.user_id = ? AND (l.workspace IS NULL OR l.workspace = ?) AND l.status != 'finalizado'");
            $stmtLoans->execute([$userId, $workspace]);
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

        $workspace = get_active_workspace();

        if ($workspace === 'business') {
            $systemPrompt = "Eres 'Ábaco Business', el mentor de negocios, consultor financiero de PYMEs y asesor táctico de emprendimientos oficiales de la aplicación Ábaco.\n"
                          . "Tu misión principal es ayudar al usuario a aumentar las ventas de su negocio, optimizar el margen de ganancia neta, controlar la caja chica diaria, reducir costos operativos y mantener al día el cobro a clientes y pago a proveedores.\n\n"
                          . "PRINCIPIOS DE CRECIMIENTO DE NEGOCIOS Y PYMES QUE DEBES ENSEÑAR:\n"
                          . "1. Control de Flujo de Caja (Cashflow Diarios): El flujo de caja es el motor vital del negocio. Registra cada venta diaria y anticipa los compromisos de arriendo, servicios, proveedores y nómina.\n"
                          . "2. Margen de Ganancia Bruta y Neta: Ayuda al usuario a calcular el margen real de sus productos o servicios descontando costos directos y gastos fijos.\n"
                          . "3. Gestión de Cuentas por Cobrar (Clientes/Fiados): Utiliza el módulo de Clientes/Préstamos para controlar las ventas a crédito y evitar que la cartera morosa ahoque la liquidez.\n"
                          . "4. Separación de Bolsillos y Sueldo del Emprendedor: Asigna un sueldo fijo al emprendedor como gasto operativo del negocio y deja la utilidad restante para reinversión en inventario o activos.\n\n"
                          . "TUTORIAL DE HERRAMIENTAS DE ÁBACO EN MODO NEGOCIO:\n"
                          . "- Modo Negocio (Espacio Activo): Todo lo que registras aquí (caja, ventas, gastos de proveedores, cuentas de empresa) está 100% separado de tus finanzas personales.\n"
                          . "- Registro Rápido por Voz o Escáner: Puedes dictar por voz ventas del día (ej: 'Venta de mercancía 150.000 en efectivo') o escanear facturas de compra de insumos.\n"
                          . "- Módulo de Clientes y Cobros: Para registrar créditos o fiados a clientes del negocio con recordatorios de pago.\n\n"
                          . "Aquí está el resumen del estado financiero actual de este NEGOCIO:\n"
                          . $summary . "\n"
                          . "INSTRUCCIÓN DE RESPUESTA (OBLIGATORIA): Responde de forma CONCRETA, DIRECTA Y CORTA (máximo 2 párrafos breves o 3 viñetas concisas). Sé ejecutivo, ve al grano sin rodeos y sin textos largos.";
        } else {
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
                          . "INSTRUCCIÓN DE RESPUESTA (OBLIGATORIA): Responde de forma CONCRETA, DIRECTA Y CORTA (máximo 2 párrafos breves o 3 viñetas concisas). Sé conversacional, ve al grano sin rodeos y sin textos extensos.";
        }

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

    // 4. DIAGNÓSTICO FINANCIERO PERSONAL 360° (Basado en el Prompt Estructurado de 5 Fases)
    if ($action === 'financial_diagnosis') {
        $input = json_decode(file_get_contents('php://input'), true);
        $userNotes = trim($input['user_notes'] ?? '');

        $workspace = get_active_workspace();

        // 1. Obtener datos financieros reales del usuario
        $accounts = [];
        $recentTransactions = [];
        $loans = [];
        $budgets = [];

        try {
            $stmtAccounts = $db->prepare("SELECT name, type, balance, currency FROM accounts WHERE user_id = ? AND (workspace IS NULL OR workspace = ?)");
            $stmtAccounts->execute([$userId, $workspace]);
            $accounts = $stmtAccounts->fetchAll();
        } catch (Exception $e) {}

        try {
            $stmtTx = $db->prepare("SELECT t.type, t.amount, t.description, t.date, c.name as category 
                                    FROM transactions t 
                                    LEFT JOIN categories c ON t.category_id = c.id 
                                    WHERE t.user_id = ? AND (t.workspace IS NULL OR t.workspace = ?) AND MONTH(t.date) = MONTH(CURRENT_DATE()) AND YEAR(t.date) = YEAR(CURRENT_DATE())");
            $stmtTx->execute([$userId, $workspace]);
            $recentTransactions = $stmtTx->fetchAll();
        } catch (Exception $e) {}

        try {
            $stmtLoans = $db->prepare("SELECT l.amount, l.type, l.status, c.name as person_name 
                                       FROM loans l 
                                       LEFT JOIN loan_clients c ON l.client_id = c.id 
                                       WHERE l.user_id = ? AND (l.workspace IS NULL OR l.workspace = ?) AND l.status != 'finalizado'");
            $stmtLoans->execute([$userId, $workspace]);
            $loans = $stmtLoans->fetchAll();
        } catch (Exception $e) {}

        try {
            $stmtBudgets = $db->prepare("SELECT b.amount, c.name as category_name FROM budgets b LEFT JOIN categories c ON b.category_id = c.id WHERE b.user_id = ? AND b.month = MONTH(CURRENT_DATE()) AND b.year = YEAR(CURRENT_DATE())");
            $stmtBudgets->execute([$userId]);
            $budgets = $stmtBudgets->fetchAll();
        } catch (Exception $e) {}

        // Formatear la situación real del usuario
        $totalBalance = 0;
        $summaryData = "CUENTAS Y SALDOS:\n";
        foreach ($accounts as $acc) {
            $totalBalance += floatval($acc['balance']);
            $summaryData .= "- {$acc['name']} ({$acc['type']}): {$acc['balance']} {$acc['currency']}\n";
        }

        $totalIncomeMonth = 0;
        $totalExpenseMonth = 0;
        foreach ($recentTransactions as $tx) {
            if ($tx['type'] === 'ingreso') $totalIncomeMonth += floatval($tx['amount']);
            if ($tx['type'] === 'egreso') $totalExpenseMonth += floatval($tx['amount']);
        }

        $summaryData .= "\nMOVIMIENTOS DEL MES ACTUAL:\n";
        $summaryData .= "- Total Ingresos: {$totalIncomeMonth}\n";
        $summaryData .= "- Total Egresos: {$totalExpenseMonth}\n";
        $summaryData .= "- Ahorro Neto del Mes: " . ($totalIncomeMonth - $totalExpenseMonth) . "\n";

        if (!empty($loans)) {
            $summaryData .= "\nPRÉSTAMOS / CARTERA / DEUDAS:\n";
            foreach ($loans as $l) {
                $summaryData .= "- {$l['type']} a/de {$l['person_name']}: {$l['amount']} (Estado: {$l['status']})\n";
            }
        }

        if (!empty($budgets)) {
            $summaryData .= "\nPRESUPUESTOS DEL MES:\n";
            foreach ($budgets as $b) {
                $summaryData .= "- Categoría {$b['category_name']}: Límite {$b['amount']}\n";
            }
        }

        if (!empty($userNotes)) {
            $summaryData .= "\nNOTAS Y OBJETIVOS EXPRESADOS POR EL USUARIO:\n{$userNotes}\n";
        }

        $systemPrompt = "Actúa como asesor financiero personal con amplia experiencia en finanzas personales, planificación de deudas, ahorro e inversión para personas comunes (no expertos en finanzas).\n\n"
                      . "La situación financiera real del usuario extraída de su aplicación es:\n"
                      . "{$summaryData}\n\n"
                      . "Con base en esa información, realiza un análisis completo siguiendo estrictamente esta estructura de 5 secciones en formato Markdown limpio:\n\n"
                      . "### 1. Diagnóstico general\n"
                      . "- Resume la situación financiera actual en términos simples.\n"
                      . "- Identifica los 3 problemas o riesgos más urgentes detectados (ej. sobreendeudamiento, falta de fondo de emergencia, gastos hormiga, ausencia de ahorro, etc.).\n"
                      . "- Señala también 1-2 fortalezas o aspectos positivos de su situación, si los hay.\n\n"
                      . "### 2. Plan de acción priorizado\n"
                      . "- Da un plan claro y realista dividido en:\n"
                      . "  a) Qué hacer esta semana (acciones inmediatas y de bajo esfuerzo).\n"
                      . "  b) Qué hacer este mes (ajustes de mediano plazo).\n"
                      . "  c) Qué hacer en los próximos 3-6 meses (metas de fondo).\n"
                      . "- Prioriza según impacto y facilidad de ejecución, no solo por lógica financiera teórica.\n\n"
                      . "### 3. Escenarios y alternativas\n"
                      . "- Si hay más de un camino posible (ej. pagar deuda vs. ahorrar primero), explica los pros y contras de cada uno aplicado a su caso.\n"
                      . "- Indica qué harías tú en su lugar y por qué.\n\n"
                      . "### 4. Puntos ciegos\n"
                      . "- Señala 2-3 preguntas clave que probablemente el usuario no se ha hecho para tomar mejores decisiones (ej. sobre riesgos, seguros, metas a largo plazo, etc.).\n\n"
                      . "### 5. Cierre\n"
                      . "- Resume en 3-4 líneas lo más importante que debe recordar y hacer primero.\n\n"
                      . "Reglas para tu respuesta:\n"
                      . "- Sé directo y práctico, evita explicaciones teóricas innecesarias.\n"
                      . "- Usa lenguaje simple, sin tecnicismos financieros salvo que sean indispensables (y en ese caso, explícalos brevemente).\n"
                      . "- No asumas datos que no se te dieron.";

        $payload = [
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [
                        ["text" => $systemPrompt]
                    ]
                ]
            ]
        ];

        try {
            $result = callGemini($payload, $apiKeyToUse);
            
            if (isset($result['error'])) {
                $googleError = $result['error']['message'] ?? 'Error de la API de Google.';
                echo json_encode(["error" => "Error de la API de Google: " . $googleError]);
                exit();
            }
            
            $analysis = $result['candidates'][0]['content']['parts'][0]['text'] ?? 'No se pudo generar el diagnóstico.';
            echo json_encode(["diagnosis" => $analysis, "summary" => $summaryData]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al generar diagnóstico: " . $e->getMessage()]);
        }
        exit();
    }
}

http_response_code(404);
echo json_encode(["error" => "Acción de IA no encontrada."]);
