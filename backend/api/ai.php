<?php
// C:\laragon\www\control-finanzas\backend\api\ai.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../lib/gemini_response.php';
require_once __DIR__ . '/../lib/ai_prompts.php';
require_once __DIR__ . '/../lib/ai_providers.php';
require_once __DIR__ . '/../lib/finance_analytics.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Clave y proveedor de IA de la petición (cabeceras). Cada usuario trae SU
// PROPIA clave y elige con cuál proveedor trabajar (Gemini o Groq); el
// nombre de la cabecera de la clave se conserva por compatibilidad.
$userApiKey = '';
$providerHeader = '';
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (isset($headers['X-Gemini-API-Key'])) {
    $userApiKey = trim($headers['X-Gemini-API-Key']);
} elseif (isset($_SERVER['HTTP_X_GEMINI_API_KEY'])) {
    $userApiKey = trim($_SERVER['HTTP_X_GEMINI_API_KEY']);
}
if (isset($headers['X-AI-Provider'])) {
    $providerHeader = trim($headers['X-AI-Provider']);
} elseif (isset($_SERVER['HTTP_X_AI_PROVIDER'])) {
    $providerHeader = trim($_SERVER['HTTP_X_AI_PROVIDER']);
}

// Cada usuario usa exclusivamente SU PROPIA clave (ver Ajustes -> IA
// Personal). No existe una clave compartida por defecto: con varios usuarios
// activos, una sola clave se agota rápido y además mezclaría las consultas de
// todos contra el mismo cupo/cuenta.
$apiKeyToUse = $userApiKey;
$aiProvider = ai_provider_for_request($providerHeader, $apiKeyToUse);

if (empty($apiKeyToUse)) {
    http_response_code(400);
    echo json_encode([
        "error" => "Necesitas vincular tu propia clave de IA (Gemini o Groq) para usarla.",
        "details" => "Ve a Ajustes → IA Personal, elige Gemini o Groq y sigue los pasos para obtener tu clave gratis. Toma menos de un minuto."
    ]);
    exit();
}

const AI_MODELS_CACHE_SECONDS = 21600;

/** Lee una lista de modelos guardada hace menos de 6 horas, o null. */
function ai_models_cache_read($file) {
    $cached = @json_decode((string) @file_get_contents($file), true);
    if (is_array($cached) && isset($cached['at'], $cached['data']) && time() - intval($cached['at']) < AI_MODELS_CACHE_SECONDS) {
        return $cached['data'];
    }
    return null;
}

function ai_models_cache_write($file, $data) {
    @file_put_contents($file, json_encode(['at' => time(), 'data' => $data]));
}

/**
 * Una llamada HTTP con cuerpo JSON. La clave viaja SIEMPRE en una cabecera
 * y nunca en la URL (las URLs quedan en logs de proxies y del servidor), y
 * con el certificado del proveedor verificado.
 * Devuelve [código HTTP, cuerpo|false, error de curl].
 */
function ai_http_json($url, array $headers, ?array $body, $timeout) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int) max(1, $timeout));
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $response, $error];
}

/**
 * Modelos de Gemini a probar, en orden. Pregunta a Google cuáles existen (la
 * lista se guarda 6 horas en un archivo temporal, es la misma para todos) y,
 * si no responde, usa los nombres conocidos: ver gemini_model_chain().
 *
 * POR QUÉ: Google retira modelos todo el tiempo (Gemini 2.0 se apagó el 1 de
 * junio de 2026; la línea 2.5 el 16 de octubre de 2026) y una lista escrita a
 * mano se desactualiza. 'gemini-flash-latest' va primero porque es un alias
 * que Google mantiene apuntando al Flash vigente.
 */
function gemini_models_for_key($apiKey) {
    $cacheFile = sys_get_temp_dir() . '/abaco_gemini_models.json';
    $cached = ai_models_cache_read($cacheFile);
    if (is_array($cached)) {
        return gemini_model_chain($cached);
    }

    [, $response] = ai_http_json('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200', ['x-goog-api-key: ' . $apiKey], null, 4);
    $discovered = $response ? gemini_pick_models(json_decode($response, true)) : [];
    if (!empty($discovered)) {
        ai_models_cache_write($cacheFile, $discovered);
    }
    return gemini_model_chain($discovered);
}

/** Dirección base de Groq. Se puede apuntar a otro servidor con GROQ_BASE_URL (pruebas). */
function groq_base_url() {
    return rtrim(getenv('GROQ_BASE_URL') ?: 'https://api.groq.com/openai/v1', '/');
}

/**
 * Modelos de Groq a probar, del más capaz al menos (ver ai_groq_pick_models:
 * hay un piso de calidad). Si Groq no responde la lista, se usan los nombres
 * conocidos; si responde y ninguno califica, devuelve [] y NO se inventa uno.
 */
function groq_models_for_key($apiKey, $needsVision) {
    $cacheFile = sys_get_temp_dir() . '/abaco_groq_models.json';
    $listing = ai_models_cache_read($cacheFile);

    if (!is_array($listing)) {
        [, $response] = ai_http_json(groq_base_url() . '/models', ['Authorization: Bearer ' . $apiKey], null, 4);
        $decoded = $response ? json_decode($response, true) : null;
        if (is_array($decoded) && !empty($decoded['data']) && is_array($decoded['data'])) {
            $listing = ['data' => array_map(fn($m) => ['id' => $m['id'] ?? '', 'active' => $m['active'] ?? true], $decoded['data'])];
            ai_models_cache_write($cacheFile, $listing);
        }
    }

    if (!is_array($listing)) {
        return ai_groq_fallback_models($needsVision);
    }
    return ai_groq_pick_models($listing, $needsVision);
}

function ai_models_for_key($apiKey, $provider, $needsVision) {
    return $provider === 'groq' ? groq_models_for_key($apiKey, $needsVision) : gemini_models_for_key($apiKey);
}

/**
 * Una petición a UN modelo de UN proveedor, ya clasificada (ver
 * gemini_classify_response / ai_openai_classify_response). null si ni
 * siquiera hubo conexión.
 */
function ai_request_once($provider, $modelName, array $payload, $apiKey, $timeout) {
    if ($provider === 'groq') {
        [$code, $response, $error] = ai_http_json(
            groq_base_url() . '/chat/completions',
            ['Authorization: Bearer ' . $apiKey],
            ai_openai_payload_from_gemini($payload, $modelName),
            $timeout
        );
        if ($error) return null;
        return ai_openai_classify_response($code, json_decode((string) $response, true), 'groq');
    }

    [, $response, $error] = ai_http_json(
        'https://generativelanguage.googleapis.com/v1beta/models/' . $modelName . ':generateContent',
        ['x-goog-api-key: ' . $apiKey],
        $payload,
        $timeout
    );
    if ($error) return null;
    $classified = gemini_classify_response(json_decode((string) $response, true));
    $classified['provider'] = 'gemini';
    return $classified;
}

/**
 * Llama al proveedor de IA elegido y devuelve una respuesta YA CLASIFICADA
 * (ver backend/lib/gemini_response.php): ['status' => 'ok'|'error'|..., 'text' => ...].
 *
 * Antes devolvía el JSON crudo y cada endpoint lo interpretaba por su cuenta
 * mirando solo si venía un campo 'error'. Eso dejaba pasar como buenas las
 * respuestas que llegan con HTTP 200 y sin texto, que es justo lo que
 * rompía la IA en producción.
 *
 * RAZONAMIENTO (Gemini): los Gemini 3 "piensan" antes de responder y esos
 * tokens salen del MISMO presupuesto (maxOutputTokens) que la respuesta
 * visible. Con prompts largos el modelo se quedaba sin espacio y terminaba
 * con finishReason=MAX_TOKENS y cero texto, sin error alguno. Por eso se
 * pide el nivel de razonamiento más bajo Y se deja un presupuesto amplio. En
 * los flash 3.x el razonamiento NO se puede apagar del todo, y el parámetro
 * antiguo (thinkingBudget) lo ignoran: el que aplica es
 * thinkingConfig.thinkingLevel.
 */
function callAI($payload, $apiKey, $provider = 'gemini') {
    $needsVision = ai_payload_has_image($payload);
    $models = ai_models_for_key($apiKey, $provider, $needsVision);
    $name = ai_provider_name($provider);

    if (empty($models)) {
        return [
            'status' => 'error', 'text' => '', 'kind' => 'no_model', 'finish_reason' => null, 'provider' => $provider,
            'message' => $needsVision
                ? "Ningún modelo de tu cuenta de {$name} puede leer imágenes, así que con esta clave no se pueden escanear recibos. Vincula una clave de Gemini en Ajustes → IA Personal para usar el escáner."
                : "Tu cuenta de {$name} no tiene ningún modelo con la capacidad que necesita el Asesor IA (los modelos pequeños inventan cifras). Prueba con la clave de otro proveedor en Ajustes → IA Personal.",
        ];
    }

    // Configuración base (respeta lo que ya traiga el endpoint que llama).
    if (!isset($payload['generationConfig'])) {
        $payload['generationConfig'] = [];
    }
    if (!isset($payload['generationConfig']['maxOutputTokens'])) {
        $payload['generationConfig']['maxOutputTokens'] = 8192;
    }
    if ($provider === 'gemini' && !isset($payload['generationConfig']['thinkingConfig'])) {
        $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'low'];
    }

    // Límite de tiempo TOTAL duro para toda la función, no por petición: el
    // servidor/proxy corta la conexión a los 30-60s y ahí el usuario recibía
    // la página de error HTML del servidor en vez de una respuesta de la app
    // ("Unexpected token '<'... is not valid JSON").
    $deadline = microtime(true) + 25;

    $lastClassified = null;
    $lastRealError = null;   // último fallo que NO sea "este modelo no existe"
    $dropThinking = false;   // un modelo rechazó el parámetro de razonamiento
    $bumpedTokens = false;   // ya se amplió el presupuesto una vez

    foreach ($models as $modelName) {
        if (microtime(true) >= $deadline) break;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 1) break 2;

            $body = $payload;
            if ($dropThinking) {
                unset($body['generationConfig']['thinkingConfig']);
            }

            $classified = ai_request_once($provider, $modelName, $body, $apiKey, min(15, $remaining));

            if ($classified === null) {   // sin conexión
                if ($deadline - microtime(true) > 1) usleep(300000);
                continue;
            }

            $lastClassified = $classified;
            if (!($classified['status'] === 'error' && $classified['kind'] === 'model_gone')) {
                $lastRealError = $classified;
            }

            if ($classified['status'] === 'ok') {
                $classified['model'] = $modelName;
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
                    // Sin cupo, cupo del día agotado, modelo sobrecargado o
                    // petición demasiado grande para ese modelo: reintentar
                    // el mismo no sirve de nada. Cada modelo tiene su propia
                    // cuota y su propia carga, así que se pasa al siguiente.
                    if (in_array(gemini_rate_limit_detail($classified), ['daily', 'zero', 'overloaded', 'too_large'], true)) {
                        break;
                    }
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
            // doble de espacio antes de pasar al siguiente modelo. Solo en
            // Gemini: Groq limita lo que se puede pedir de salida.
            if ($classified['status'] === 'truncated' && !$bumpedTokens && $provider === 'gemini') {
                $bumpedTokens = true;
                $payload['generationConfig']['maxOutputTokens'] =
                    min(32768, $payload['generationConfig']['maxOutputTokens'] * 2);
                continue;
            }

            // Bloqueado por los filtros del proveedor: otro modelo lo bloqueará igual.
            if ($classified['status'] === 'blocked') {
                return $classified;
            }

            break; // respuesta vacía sin motivo: probar el siguiente modelo
        }
    }

    // Si algún modelo no existía, su 404 no debe tapar el motivo real del
    // fallo (por ejemplo, que el principal estaba sobrecargado).
    return $lastRealError ?? $lastClassified ?? [
        'status' => 'empty',
        'text' => '',
        'kind' => 'no_response',
        'finish_reason' => null,
        'message' => '',
        'provider' => $provider,
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
            $result = callAI($payload, $apiKeyToUse, $aiProvider);

            if ($result['status'] !== 'ok') {
                http_response_code(400);
                // 'kind' le dice a Ajustes si la clave es mala (invalid_key) o si
                // fue algo pasajero (límite, servidor saturado): en ese caso la
                // clave se conserva en uso.
                echo json_encode(["success" => false, "kind" => $result['kind'] ?? null, "error" => gemini_status_message($result)]);
                exit();
            }

            echo json_encode([
                "success" => true,
                "provider" => $aiProvider,
                "model" => $result['model'] ?? null,
                "message" => "Conexión verificada: tu clave de " . ai_provider_name($aiProvider) . " funciona correctamente" . (!empty($result['model']) ? " (modelo {$result['model']})." : ".")
            ]);
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
            $result = callAI($payload, $apiKeyToUse, $aiProvider);

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
            $result = callAI($payload, $apiKeyToUse, $aiProvider);
            // Reintento de contingencia sin responseMimeType si falla
            if ($result['status'] !== 'ok') {
                unset($payload['generationConfig']['responseMimeType']);
                $result = callAI($payload, $apiKeyToUse, $aiProvider);
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

        $workspace = get_active_workspace();

        // El resumen ya no son 10 movimientos y dos totales: es la situación
        // completa y calculada (ver backend/lib/finance_analytics.php).
        $summary = fa_context_to_text(fa_build_context($db, $userId, $workspace, date('Y-m-d')));

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
            $result = callAI($payload, $apiKeyToUse, $aiProvider);

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
        // Antes estas consultas no filtraban por espacio de trabajo (mezclaban
        // el presupuesto personal con el del negocio) y solo le pasaban a la
        // IA límite y gastado: sin ingresos ni historial no podía proponer
        // nada con fundamento.
        $workspace = get_active_workspace();
        $ctx = fa_build_context($db, $userId, $workspace, date('Y-m-d'));

        $context = fa_context_to_text($ctx) . "\n\nCATEGORÍAS PRESUPUESTADAS (usa estos ID en 'proposed_budgets'):\n";
        foreach ($ctx['budget']['categories'] as $bc) {
            $context .= "- {$bc['name']} (ID de Categoría: {$bc['category_id']}): Límite: " . round($bc['limit']) . " | Gastado: " . round($bc['spent']) . "\n";
        }
        if (empty($ctx['budget']['categories'])) {
            $context .= "- (ninguna todavía)\n";
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
            $result = callAI($payload, $apiKeyToUse, $aiProvider);

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

        $summaryData = fa_context_to_text(fa_build_context($db, $userId, $workspace, date('Y-m-d')));

        if (!empty($userNotes)) {
            $summaryData .= "\n\nNOTAS Y OBJETIVOS EXPRESADOS POR EL USUARIO:\n{$userNotes}\n";
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
            // el default de callAI() para no cortarse a mitad de camino
            // (el razonamiento del modelo sale de este mismo cupo de tokens).
            "generationConfig" => [
                "maxOutputTokens" => 16384
            ]
        ];

        try {
            $result = callAI($payload, $apiKeyToUse, $aiProvider);

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

    // 5. PLAN DE INVERSIÓN. La decisión de SI puede invertir y CUÁNTO la toma
    // el sistema (fa_investment_readiness); la IA solo arma el plan dentro de
    // ese límite, según el horizonte y el perfil de riesgo que eligió el usuario.
    if ($action === 'investment_plan') {
        $input = json_decode(file_get_contents('php://input'), true);
        $horizons = ['corto' => 'menos de 1 año', 'medio' => 'entre 1 y 5 años', 'largo' => 'más de 5 años'];
        $risks = ['conservador' => 'conservador (no tolera ver bajar su capital)', 'moderado' => 'moderado (acepta bajas temporales a cambio de más rendimiento)', 'arriesgado' => 'arriesgado (acepta alta volatilidad buscando el mayor rendimiento)'];
        $horizon = $horizons[$input['horizon'] ?? ''] ?? $horizons['medio'];
        $risk = $risks[$input['risk'] ?? ''] ?? $risks['moderado'];

        $workspace = get_active_workspace();
        $ctx = fa_build_context($db, $userId, $workspace, date('Y-m-d'));

        $prompt = ai_prompt_fill(ai_prompt_get($db, 'investment_plan'), [
            '{{RESUMEN_FINANCIERO}}' => fa_context_to_text($ctx),
            '{{HORIZONTE}}' => $horizon,
            '{{PERFIL_RIESGO}}' => $risk,
        ]);

        $payload = [
            "contents" => [["role" => "user", "parts" => [["text" => $prompt]]]],
            "generationConfig" => ["maxOutputTokens" => 16384]
        ];

        try {
            $result = callAI($payload, $apiKeyToUse, $aiProvider);

            if ($result['status'] !== 'ok') {
                echo json_encode(["error" => gemini_status_message($result)]);
                exit();
            }

            echo json_encode(["plan" => $result['text'], "readiness" => $ctx['readiness']]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al generar el plan de inversión: " . $e->getMessage()]);
        }
        exit();
    }
}

http_response_code(404);
echo json_encode(["error" => "Acción de IA no encontrada."]);
