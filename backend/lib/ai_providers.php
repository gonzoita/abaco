<?php
// backend/lib/ai_providers.php
//
// Proveedores de IA además de Gemini. Cada usuario trae SU PROPIA clave
// (gratuita) y elige con cuál proveedor trabajar: hoy Gemini o Groq.
//
// La app habla "formato Gemini" por dentro (los prompts y ai.php arman
// payloads con contents/parts/generationConfig). Para Groq, que usa el
// formato de OpenAI (chat/completions), aquí se traduce la petición y se
// clasifica la respuesta con la MISMA forma que gemini_classify_response(),
// de modo que ai.php no sabe con quién habla.
//
// Todo lo de este archivo es puro (sin red ni BD) para poder probarlo.

require_once __DIR__ . '/gemini_response.php';

const AI_PROVIDERS = [
    'gemini' => ['name' => 'Gemini', 'keys_url' => 'aistudio.google.com/apikey'],
    'groq' => ['name' => 'Groq', 'keys_url' => 'console.groq.com/keys'],
];

// Groq cuenta contra el límite por minuto (TPM) lo que se SOLICITA de
// salida, no lo que se usa. Pedir 16.000 tokens de respuesta en el nivel
// gratuito hace que casi todo se rechace por "petición demasiado grande".
const AI_GROQ_MAX_OUTPUT_TOKENS = 4096;

/**
 * Qué proveedor usar: el que declara el cliente (cabecera X-AI-Provider) o,
 * si no la manda, el que delata el prefijo de la clave (las de Groq empiezan
 * por "gsk_"). Cualquier otra cosa es Gemini, que es lo que había antes.
 */
function ai_provider_for_request($headerProvider, $apiKey) {
    $declared = strtolower(trim((string) $headerProvider));
    if (isset(AI_PROVIDERS[$declared])) {
        return $declared;
    }
    return strpos(trim((string) $apiKey), 'gsk_') === 0 ? 'groq' : 'gemini';
}

function ai_provider_name($provider) {
    return AI_PROVIDERS[$provider]['name'] ?? 'Gemini';
}

/** ¿El payload (formato Gemini) lleva alguna imagen? Solo entonces hace falta un modelo con visión. */
function ai_payload_has_image(array $payload) {
    foreach (($payload['contents'] ?? []) as $content) {
        foreach (($content['parts'] ?? []) as $part) {
            if (isset($part['inlineData']['data'])) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Traduce un payload en formato Gemini al cuerpo de chat/completions.
 * - 'model' (rol de Gemini) pasa a 'assistant'.
 * - Un mensaje solo de texto va como texto plano: es lo único que aceptan
 *   TODOS los modelos. Solo los mensajes con imagen usan la lista de partes.
 * - responseMimeType JSON pasa a response_format json_object.
 * - La salida se limita a AI_GROQ_MAX_OUTPUT_TOKENS (ver arriba).
 * - Los gpt-oss "razonan" y ese razonamiento sale del mismo tope de tokens:
 *   se les pide el esfuerzo mínimo para que no se les acabe antes de escribir.
 */
function ai_openai_payload_from_gemini(array $payload, $model) {
    $messages = [];
    foreach (($payload['contents'] ?? []) as $content) {
        $role = (($content['role'] ?? 'user') === 'model') ? 'assistant' : 'user';
        $parts = [];
        $texts = [];
        $hasImage = false;
        foreach (($content['parts'] ?? []) as $part) {
            if (isset($part['text'])) {
                $texts[] = (string) $part['text'];
                $parts[] = ['type' => 'text', 'text' => (string) $part['text']];
            } elseif (isset($part['inlineData']['data'])) {
                $mime = $part['inlineData']['mimeType'] ?? 'image/jpeg';
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$part['inlineData']['data']}"]];
                $hasImage = true;
            }
        }
        $messages[] = ['role' => $role, 'content' => $hasImage ? $parts : implode("\n", $texts)];
    }

    $config = $payload['generationConfig'] ?? [];
    $body = ['model' => $model, 'messages' => $messages];

    $requested = isset($config['maxOutputTokens']) ? intval($config['maxOutputTokens']) : AI_GROQ_MAX_OUTPUT_TOKENS;
    $body['max_completion_tokens'] = max(256, min($requested, AI_GROQ_MAX_OUTPUT_TOKENS));

    if (isset($config['temperature'])) {
        $body['temperature'] = floatval($config['temperature']);
    }
    if (($config['responseMimeType'] ?? '') === 'application/json') {
        $body['response_format'] = ['type' => 'json_object'];
    }
    if (stripos($model, 'gpt-oss') !== false) {
        $body['reasoning_effort'] = 'low';
    }
    return $body;
}

/**
 * Segundos que indica un mensaje tipo "Please try again in 1m23.4s", "in 7m"
 * o "in 250ms". null si no dice.
 */
function ai_parse_retry_seconds($message) {
    if (!preg_match('/try again in\s+((?:\d+(?:\.\d+)?\s*(?:h|ms|m|s)\s*)+)/i', (string) $message, $found)) {
        return null;
    }
    $seconds = 0.0;
    preg_match_all('/(\d+(?:\.\d+)?)\s*(ms|h|m|s)/i', $found[1], $units, PREG_SET_ORDER);
    foreach ($units as $unit) {
        $value = (float) $unit[1];
        switch (strtolower($unit[2])) {
            case 'h': $seconds += $value * 3600; break;
            case 'm': $seconds += $value * 60; break;
            case 's': $seconds += $value; break;
            case 'ms': $seconds += $value / 1000; break;
        }
    }
    return (int) ceil($seconds);
}

/**
 * Clasifica una respuesta de chat/completions con la misma forma que
 * gemini_classify_response() (status/kind/message + los datos de cuota).
 */
function ai_openai_classify_response($httpCode, $decoded, $provider = 'groq') {
    $httpCode = intval($httpCode);
    $base = ['text' => '', 'finish_reason' => null, 'message' => '', 'provider' => $provider];

    if (!is_array($decoded) || empty($decoded)) {
        if ($httpCode >= 500) {
            return array_merge($base, ['status' => 'error', 'kind' => 'rate_limit', 'api_status' => 'UNAVAILABLE', 'quota_ids' => [], 'quota_zero' => false, 'retry_seconds' => null, 'message' => "El servicio respondió con un error {$httpCode}."]);
        }
        return array_merge($base, ['status' => 'empty', 'kind' => 'no_response']);
    }

    if (isset($decoded['error'])) {
        $error = is_array($decoded['error']) ? $decoded['error'] : ['message' => (string) $decoded['error']];
        $message = (string) ($error['message'] ?? 'Error del proveedor de IA.');
        $code = strtolower((string) ($error['code'] ?? ''));

        if ($httpCode === 401 || $code === 'invalid_api_key' || preg_match('/invalid api key|incorrect api key|invalid_api_key/i', $message)) {
            $kind = 'invalid_key';
        } elseif (in_array($code, ['model_not_found', 'model_decommissioned', 'model_terminated'], true)
            || $httpCode === 404 || preg_match('/does not exist|decommissioned|no longer supported/i', $message)) {
            $kind = 'model_gone';
        } elseif (in_array($httpCode, [413, 429, 500, 502, 503, 504], true) || $code === 'rate_limit_exceeded'
            || preg_match('/rate limit|quota|too large|over capacity|overloaded/i', $message)) {
            $kind = 'rate_limit';
        } elseif ($httpCode === 400 || $code === 'json_validate_failed') {
            $kind = 'bad_request';
        } else {
            $kind = 'other';
        }

        $quotaIds = [];
        if (preg_match('/\((?:TPD|RPD)\)|per day/i', $message)) {
            $quotaIds[] = 'PerDay';
        } elseif (preg_match('/\((?:TPM|RPM)\)|per minute/i', $message)) {
            $quotaIds[] = 'PerMinute';
        }

        return array_merge($base, [
            'status' => 'error',
            'kind' => $kind,
            'message' => $message,
            'api_status' => ($httpCode >= 500 || preg_match('/over capacity|overloaded|unavailable/i', $message)) ? 'UNAVAILABLE' : null,
            'quota_ids' => $quotaIds,
            'quota_zero' => false,
            'too_large' => $httpCode === 413 || (bool) preg_match('/request too large|reduce your message size/i', $message),
            'retry_seconds' => ai_parse_retry_seconds($message),
        ]);
    }

    $choice = $decoded['choices'][0] ?? [];
    $finish = $choice['finish_reason'] ?? null;
    $content = $choice['message']['content'] ?? '';
    $content = is_string($content) ? $content : '';
    // Algunos modelos con razonamiento dejan su "pensamiento" dentro del texto.
    $text = trim(preg_replace('/<think>.*?<\/think>/s', '', $content));

    if ($text !== '') {
        return ['status' => 'ok', 'text' => $text, 'kind' => null, 'finish_reason' => $finish, 'message' => '', 'provider' => $provider];
    }
    if ($finish === 'content_filter') {
        return array_merge($base, ['status' => 'blocked', 'kind' => 'blocked', 'finish_reason' => $finish, 'message' => 'content_filter']);
    }
    if ($finish === 'length') {
        return array_merge($base, ['status' => 'truncated', 'kind' => 'max_tokens', 'finish_reason' => $finish]);
    }
    return array_merge($base, ['status' => 'empty', 'kind' => 'no_text', 'finish_reason' => $finish]);
}

// ---------------------------------------------------------------------------
// Elección de modelos de Groq
// ---------------------------------------------------------------------------

/** Modelos que no generan texto de chat, o que no dan la talla. */
const AI_GROQ_EXCLUDED = '/whisper|tts|playai|orpheus|guard|safeguard|embed|compound|moderation|instant|(^|[^0-9.])(1|3|7|8|9|11)b([^a-z]|$)/i';

/** Modelos capaces de leer imágenes (para el escáner de recibos). */
const AI_GROQ_VISION = '/llama-4-(scout|maverick)|vision|pixtral|qwen[^\/]*-vl|llava/i';

/**
 * De mayor a menor capacidad para las tareas de la app (asesoría financiera
 * en español, respuestas largas en Markdown y JSON confiable). Lo que no
 * aparezca aquí pero declare 30B o más entra después, por tamaño.
 */
const AI_GROQ_PREFERENCE = [
    '/gpt-oss-120b/i',
    '/kimi-k2/i',
    '/llama-4-maverick/i',
    '/llama-3\.3-70b/i',
    '/qwen3?[^\/]*(32b|235b)/i',
    '/llama-4-scout/i',
    '/gpt-oss-20b/i',
];

/**
 * Elige y ordena los modelos de Groq para una tarea. Piso de calidad: se
 * descartan los modelos pequeños (8B y menos) porque inventan cifras, y aquí
 * un número mal dicho es un consejo mal dado. Con $needsVision solo quedan
 * los que leen imágenes. Devuelve hasta 4 ids; [] si ninguno califica.
 */
function ai_groq_pick_models($listing, $needsVision = false) {
    $ranked = [];
    foreach (($listing['data'] ?? []) as $model) {
        if (!is_array($model) || empty($model['id'])) continue;
        if (array_key_exists('active', $model) && $model['active'] === false) continue;
        $id = (string) $model['id'];
        if (preg_match(AI_GROQ_EXCLUDED, $id)) continue;
        if ($needsVision && !preg_match(AI_GROQ_VISION, $id)) continue;

        $score = null;
        foreach (AI_GROQ_PREFERENCE as $position => $pattern) {
            if (preg_match($pattern, $id)) {
                $score = 1000 - $position;
                break;
            }
        }
        if ($score === null) {
            // No lo conocemos: solo entra si declara 30B o más.
            if (!preg_match('/(\d+(?:\.\d+)?)b/i', $id, $size) || (float) $size[1] < 30) continue;
            $score = (float) $size[1];
        }
        $ranked[] = ['id' => $id, 'score' => $score];
    }

    usort($ranked, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return array_slice(array_column($ranked, 'id'), 0, 4);
}

/** Orden de prueba si no se pudo preguntar a Groq qué modelos hay. */
function ai_groq_fallback_models($needsVision = false) {
    return $needsVision
        ? ['meta-llama/llama-4-scout-17b-16e-instruct', 'meta-llama/llama-4-maverick-17b-128e-instruct']
        : ['openai/gpt-oss-120b', 'llama-3.3-70b-versatile', 'qwen/qwen3-32b', 'meta-llama/llama-4-scout-17b-16e-instruct'];
}
