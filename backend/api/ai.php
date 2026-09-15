<?php
// C:\laragon\www\control-finanzas\backend\api\ai.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../lib/gemini_response.php';
require_once __DIR__ . '/../lib/ai_prompts.php';

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

// Cada usuario usa exclusivamente SU PROPIA clave de Gemini (ver Ajustes ->
// IA Personal). Ya no existe una clave compartida por defecto: con varios
// usuarios activos, una sola clave se agota rápido y además mezclaría las
// consultas de todos contra el mismo cupo/cuenta de Google.
$apiKeyToUse = $userApiKey;

if (empty($apiKeyToUse)) {
    http_response_code(400);
    echo json_encode([
        "error" => "Necesitas vincular tu propia clave de Gemini para usar la IA.",
        "details" => "Ve a Ajustes → IA Personal (Google Gemini) y sigue los 4 pasos para obtener tu clave gratis en Google AI Studio. Toma menos de un minuto."
    ]);
    exit();
}

/**
 * Llama a la API de Gemini y devuelve una respuesta YA CLASIFICADA
 * (ver backend/lib/gemini_response.php): ['status' => 'ok'|'error'|..., 'text' => ...].
 *
 * Antes devolvía el JSON crudo de Google y cada endpoint lo interpretaba por su
 * cuenta mirando solo si venía un campo 'error'. Eso dejaba pasar como buenas
 * las respuestas que llegan con HTTP 200 y sin texto, que es justo lo que
 * rompía la IA en producción.
 *
 * Dos cosas que hay que saber de los modelos actuales:
 *
 * 1) RAZONAMIENTO: los Gemini 3 "piensan" antes de responder y esos tokens
 *    salen del MISMO presupuesto (maxOutputTokens) que la respuesta visible.
 *    Con prompts largos el modelo se quedaba sin espacio y terminaba con
 *    finishReason=MAX_TOKENS y cero texto, sin error alguno. Por eso aquí se
 *    pide el nivel de razonamiento más bajo Y se deja un presupuesto amplio.
 *    Importante: en los flash 3.x el razonamiento NO se puede apagar del todo,
 *    y el parámetro antiguo (thinkingBudget) lo ignoran: el que aplica es
 *    thinkingConfig.thinkingLevel.
 *
 * 2) MODELOS: Google los va retirando (Gemini 2.0 se apagó el 1 de junio de
 *    2026; la línea 2.5 se apaga el 16 de octubre de 2026). 'gemini-flash-latest'
 *    va primero porque es un alias que Google mantiene apuntando al Flash
 *    recomendado del momento, así no depende de que actualicemos la lista.
 */
function callGemini($payload, $apiKey) {
    $models = ['gemini-flash-latest', 'gemini-3.7-flash', 'gemini-3.5-flash', 'gemini-3.1-flash-lite'];

    // Configuración base (respeta lo que ya traiga el endpoint que llama).
    if (!isset($payload['generationConfig'])) {
        $payload['generationConfig'] = [];
    }
    if (!isset($payload['generationConfig']['maxOutputTokens'])) {
        $payload['generationConfig']['maxOutputTokens'] = 8192;
    }
    if (!isset($payload['generationConfig']['thinkingConfig'])) {
        $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'low'];
    }

    // Límite de tiempo TOTAL duro para toda la función, no por petición: el
    // servidor/proxy corta la conexión a los 30-60s y ahí el usuario recibía
    // la página de error HTML del servidor en vez de una respuesta de la app
    // ("Unexpected token '<'... is not valid JSON").
    $deadline = microtime(true) + 25;

    $lastClassified = null;
    $dropThinking = false;   // un modelo rechazó el parámetro de razonamiento
    $bumpedTokens = false;   // ya se amplió el presupuesto una vez

    foreach ($models as $modelName) {
        if (microtime(true) >= $deadline) break;
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $modelName . ":generateContent?key=" . $apiKey;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 1) break 2;

            $body = $payload;
            if ($dropThinking) {
                unset($body['generationConfig']['thinkingConfig']);
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) max(1, min(15, $remaining)));

            $response = curl_exec($ch);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                if ($deadline - microtime(true) > 1) usleep(300000);
                continue;
            }

            $classified = gemini_classify_response(json_decode($response, true));
            $lastClassified = $classified;

            if ($classified['status'] === 'ok') {
                return $classified;
            }

            if ($classified['status'] === 'error') {
                // Clave mala: reintentar con otro modelo no arregla nada.
                if ($classified['kind'] === 'invalid_key') {
                    return $classified;
                }
                // El modelo no acepta el parámetro de razonamiento (Google le
                // ha cambiado el nombre entre generaciones): se reintenta sin
                // él, aquí y en los modelos siguientes.
                if ($classified['kind'] === 'bad_thinking' && !$dropThinking) {
                    $dropThinking = true;
                    continue;
                }
                if ($classified['kind'] === 'model_gone') {
                    break; // este modelo ya no existe: probar el siguiente
                }
                if ($classified['kind'] === 'rate_limit') {
                    // Es el límite por minuto de la clave gratuita, no un fallo
                    // de red: un reintento inmediato no sirve de nada.
                    $wait = $attempt === 1 ? 2.0 : 0.5;
                    if ($deadline - microtime(true) > $wait + 1) {
                        usleep((int) ($wait * 1000000));
                    }
                    continue;
                }
                break;
            }

            // Se quedó sin tokens razonando: una segunda oportunidad con el
            // doble de espacio antes de pasar al siguiente modelo.
            if ($classified['status'] === 'truncated' && !$bumpedTokens) {
                $bumpedTokens = true;
                $payload['generationConfig']['maxOutputTokens'] =
                    min(32768, $payload['generationConfig']['maxOutputTokens'] * 2);
                continue;
            }

            // Bloqueado por los filtros de Google: otro modelo lo bloqueará igual.
            if ($classified['status'] === 'blocked') {
                return $classified;
            }

            break; // respuesta vacía sin motivo: probar el siguiente modelo
        }
    }

    return $lastClassified ?? [
        'status' => 'empty',
        'text' => '',
        'kind' => 'no_response',
        'finish_reason' => null,
        'message' => '',
    ];
}

function fallbackVoiceParser($transcript, $categoriesList, $accountsList, $defaultAccId) {
    $clean = mb_strtolower($transcript);
    
    // Tipo
    $type = 'egreso';
    if (preg_match('/ingreso|sueldo|salario|venta|cobr|abono|gananci|recib/i', $clean)) {
        $type = 'ingreso';
    }

    // Monto
    $amount = 0;
    if (preg_match('/(\d+(?:[\.,]\d+)?)\s*(?:mil|k)/i', $clean, $m)) {
        $num = (float)str_replace(',', '.', $m[1]);
        $amount = (int)($num * 1000);
    } elseif (preg_match('/(\d+(?:[\.,]\d+)?)\s*(?:millon|millón|millones|m)/i', $clean, $m)) {
        $num = (float)str_replace(',', '.', $m[1]);
        $amount = (int)($num * 1000000);
    } elseif (preg_match('/\$?\s*(\d+[\d\.,]*)/i', $clean, $m)) {
        $cleanNum = preg_replace('/[^\d]/', '', $m[1]);
        $amount = (int)$cleanNum;
    }

    // Descripción
    $desc = mb_convert_case(trim($transcript), MB_CASE_TITLE, "UTF-8");

    // Categoría
    $catId = null;
    $catName = null;
    foreach ($categoriesList as $cat) {
        $cName = mb_strtolower($cat['name']);
        if (strpos($clean, $cName) !== false) {
            $catId = $cat['id'];
            $catName = $cat['name'];
            break;
        }
    }

    // Cuenta
    $accId = $defaultAccId;
    foreach ($accountsList as $acc) {
        $aName = mb_strtolower($acc['name']);
        if (strpos($clean, $aName) !== false) {
            $accId = $acc['id'];
            break;
        }
    }

    return [
        "type" => $type,
        "amount" => $amount,
        "description" => $desc,
        "category_id" => $catId,
        "category_name" => $catName,
        "account_id" => $accId,
        "tags" => ""
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 0. VERIFICAR CLAVE (usada por Ajustes -> IA Personal al vincular una
    // clave nueva, para confirmar de inmediato que sí quedó conectada y
    // funcional en vez de solo guardarla a ciegas en el navegador).
    if ($action === 'test_key') {
        $payload = [
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [["text" => "Responde únicamente con la palabra OK, sin nada más."]]
                ]
            ]
        ];

        try {
            $result = callGemini($payload, $apiKeyToUse);

            if ($result['status'] !== 'ok') {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => gemini_status_message($result)]);
                exit();
            }

            echo json_encode(["success" => true, "message" => "Conexión verificada: tu clave de Gemini funciona correctamente."]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
        }
        exit();
    }

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

        // Construir prompt para estructurar la salida en JSON (editable desde
        // el panel de admin -> Prompts IA, ver backend/lib/ai_prompts.php)
        $prompt = ai_prompt_get($db, 'scan_receipt');

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

            if ($result['status'] !== 'ok') {
                throw new Exception(gemini_status_message($result));
            }

            // El modelo a veces envuelve el JSON en un bloque markdown ```json.
            echo gemini_strip_code_fence($result['text']);

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

        $prompt = ai_prompt_fill(ai_prompt_get($db, 'voice_transaction'), [
            '{{TRANSCRIPCION}}' => $transcript,
            '{{CATEGORIAS_JSON}}' => $categoriesJson,
            '{{CUENTAS_JSON}}' => $accountsJson,
            '{{CUENTA_DEFECTO_ID}}' => $defaultAccId,
        ]);

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
            if ($result['status'] !== 'ok') {
                unset($payload['generationConfig']['responseMimeType']);
                $result = callGemini($payload, $apiKeyToUse);
            }

            $parsedData = null;
            if ($result['status'] === 'ok') {
                $cleanText = gemini_strip_code_fence($result['text']);

                $parsedData = json_decode($cleanText, true);
                if (!$parsedData && preg_match('/\{.*\}/s', $cleanText, $matches)) {
                    $parsedData = json_decode($matches[0], true);
                }
            }

            // Fallback de análisis inteligente local en PHP si la IA no entregó JSON válido
            if (!$parsedData || !is_array($parsedData)) {
                $parsedData = fallbackVoiceParser($transcript, $categoriesList, $accountsList, $defaultAccId);
            }

            if (empty($parsedData['account_id']) && $defaultAccId) {
                $parsedData['account_id'] = $defaultAccId;
            }

            echo json_encode($parsedData, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            $fallback = fallbackVoiceParser($transcript, $categoriesList, $accountsList, $defaultAccId);
            echo json_encode($fallback, JSON_UNESCAPED_UNICODE);
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

        // NOTA: get_active_workspace() debe llamarse ANTES de usar $workspace
        // en las consultas de abajo (antes se llamaba después de usarlo, lo
        // que dejaba $workspace indefinido y rompía el filtro de espacio de
        // trabajo para estas consultas).
        $workspace = get_active_workspace();

        // Obtener resumen de las finanzas del usuario (para contextualizar)
        $accounts = [];
        $recentTransactions = [];
        $loans = [];
        $totalLiquid = 0;
        $monthIncome = 0;
        $monthExpense = 0;

        try {
            $stmtAccounts = $db->prepare("SELECT name, type, balance, currency FROM accounts WHERE user_id = ? AND (workspace IS NULL OR workspace = ?)");
            $stmtAccounts->execute([$userId, $workspace]);
            $accounts = $stmtAccounts->fetchAll();
            foreach ($accounts as $acc) {
                if (in_array($acc['type'], ['efectivo', 'banco', 'ahorro'])) {
                    $totalLiquid += floatval($acc['balance']);
                }
            }
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
            $stmtMonth = $db->prepare("SELECT type, SUM(amount) as total FROM transactions
                                       WHERE user_id = ? AND (workspace IS NULL OR workspace = ?)
                                       AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())
                                       GROUP BY type");
            $stmtMonth->execute([$userId, $workspace]);
            foreach ($stmtMonth->fetchAll() as $row) {
                if ($row['type'] === 'ingreso') $monthIncome = floatval($row['total']);
                if ($row['type'] === 'egreso') $monthExpense = floatval($row['total']);
            }
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
        $summary .= "\nSaldo líquido total (efectivo + banco + ahorro): {$totalLiquid}\n";
        $summary .= "Ingresos de este mes: {$monthIncome} | Gastos de este mes: {$monthExpense} | Ahorro neto del mes: " . ($monthIncome - $monthExpense) . "\n";
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

        $chatPromptKey = $workspace === 'business' ? 'chat_business_system' : 'chat_personal_system';
        $systemPrompt = ai_prompt_fill(ai_prompt_get($db, $chatPromptKey), [
            '{{RESUMEN_FINANCIERO}}' => $summary,
        ]);

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

            if ($result['status'] !== 'ok') {
                echo json_encode(["response" => "⚠️ " . gemini_status_message($result)]);
                exit();
            }

            echo json_encode(["response" => $result['text']]);
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

        $prompt = ai_prompt_fill(ai_prompt_get($db, 'optimize_budget'), [
            '{{CONTEXTO_PRESUPUESTOS}}' => $context,
        ]);

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

            if ($result['status'] !== 'ok') {
                throw new Exception(gemini_status_message($result));
            }

            echo gemini_strip_code_fence($result['text']);
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

        $systemPrompt = ai_prompt_fill(ai_prompt_get($db, 'financial_diagnosis'), [
            '{{RESUMEN_FINANCIERO}}' => $summaryData,
        ]);

        $payload = [
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [
                        ["text" => $systemPrompt]
                    ]
                ]
            ],
            // Respuesta de 5 secciones en markdown: necesita más margen que
            // el default de callGemini() para no cortarse a mitad de camino
            // (el razonamiento del modelo sale de este mismo cupo de tokens).
            "generationConfig" => [
                "maxOutputTokens" => 16384
            ]
        ];

        try {
            $result = callGemini($payload, $apiKeyToUse);

            if ($result['status'] !== 'ok') {
                echo json_encode(["error" => gemini_status_message($result)]);
                exit();
            }

            echo json_encode(["diagnosis" => $result['text'], "summary" => $summaryData]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al generar diagnóstico: " . $e->getMessage()]);
        }
        exit();
    }
}

http_response_code(404);
echo json_encode(["error" => "Acción de IA no encontrada."]);
