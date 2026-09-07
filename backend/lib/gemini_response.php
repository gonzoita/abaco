<?php
// backend/lib/gemini_response.php
//
// Interpretación de las respuestas de la API de Gemini, separada de ai.php
// para poder probarla de verdad (tests/GeminiResponseTest.php).
//
// POR QUÉ EXISTE ESTE ARCHIVO: la IA de la app falló tres veces seguidas en
// producción por el MISMO motivo de fondo: Gemini puede devolver HTTP 200 sin
// ningún campo 'error' y aun así no traer texto (el modelo "pensó" hasta
// agotar el presupuesto de tokens, o el contenido fue bloqueado). ai.php solo
// miraba si existía 'error', así que esos casos pasaban como respuesta válida
// y el usuario veía un genérico "no pude procesar la consulta" imposible de
// diagnosticar. Aquí cada caso se clasifica explícitamente.

/**
 * Extrae el texto de una respuesta de Gemini, uniendo todas las partes.
 * Devuelve '' si la respuesta no trae texto utilizable.
 */
function gemini_extract_text($decoded) {
    if (!is_array($decoded)) return '';
    $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
    if (!is_array($parts)) return '';

    $text = '';
    foreach ($parts as $part) {
        // Los modelos con razonamiento marcan sus partes internas con
        // "thought": esas NO son la respuesta para el usuario.
        if (!empty($part['thought'])) continue;
        if (isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }
    return trim($text);
}

/**
 * Clasifica el error de Google para decidir qué hacer (reintentar, cambiar de
 * modelo, quitar parámetros no soportados o rendirse).
 */
function gemini_error_kind($rawMessage) {
    $msg = mb_strtolower((string) $rawMessage);

    if (strpos($msg, 'api_key_invalid') !== false || strpos($msg, 'invalid api key') !== false || strpos($msg, 'api key not valid') !== false) {
        return 'invalid_key';
    }
    // Parámetro de "thinking" no soportado por este modelo. Google ha cambiado
    // el nombre de este campo entre generaciones (thinking_budget -> thinking_level),
    // así que en vez de adivinar cuál acepta cada modelo, se detecta el rechazo
    // y se reintenta sin él.
    if (strpos($msg, 'thinking') !== false || strpos($msg, 'thought') !== false) {
        return 'bad_thinking';
    }
    if (strpos($msg, 'not found') !== false || strpos($msg, 'not supported') !== false || strpos($msg, 'is not available') !== false) {
        return 'model_gone';
    }
    if (strpos($msg, 'high demand') !== false || strpos($msg, 'overloaded') !== false || strpos($msg, '503') !== false
        || strpos($msg, 'resource_exhausted') !== false || strpos($msg, 'quota') !== false || strpos($msg, 'rate limit') !== false) {
        return 'rate_limit';
    }
    if (strpos($msg, 'invalid_argument') !== false || strpos($msg, 'invalid json') !== false || strpos($msg, 'unknown name') !== false) {
        return 'bad_request';
    }
    return 'other';
}

/**
 * Clasifica una respuesta completa de Gemini.
 *
 * status:
 *  - ok         -> hay texto para el usuario (en 'text')
 *  - error      -> Google devolvió un error explícito (ver 'kind')
 *  - truncated  -> se acabó el presupuesto de tokens antes de escribir la respuesta
 *  - blocked    -> el contenido fue bloqueado por los filtros de Google
 *  - empty      -> respuesta sin texto y sin motivo declarado
 */
function gemini_classify_response($decoded) {
    if (!is_array($decoded) || empty($decoded)) {
        return ['status' => 'empty', 'text' => '', 'kind' => 'no_response', 'finish_reason' => null, 'message' => ''];
    }

    if (isset($decoded['error'])) {
        $message = $decoded['error']['message'] ?? 'Error de la API de Google.';
        return [
            'status' => 'error',
            'text' => '',
            'kind' => gemini_error_kind($message),
            'finish_reason' => null,
            'message' => $message,
        ];
    }

    $text = gemini_extract_text($decoded);
    $finishReason = $decoded['candidates'][0]['finishReason'] ?? null;

    if ($text !== '') {
        return ['status' => 'ok', 'text' => $text, 'kind' => null, 'finish_reason' => $finishReason, 'message' => ''];
    }

    // Bloqueo del prompt (llega sin 'candidates') o de la respuesta.
    $blockReason = $decoded['promptFeedback']['blockReason'] ?? null;
    if ($blockReason !== null || in_array($finishReason, ['SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST'], true)) {
        return [
            'status' => 'blocked',
            'text' => '',
            'kind' => 'blocked',
            'finish_reason' => $finishReason ?: $blockReason,
            'message' => (string) ($blockReason ?: $finishReason),
        ];
    }

    // El caso que rompía la app: el modelo agota el presupuesto de tokens
    // razonando y termina sin escribir nada. Google responde 200 y sin 'error'.
    if ($finishReason === 'MAX_TOKENS') {
        return ['status' => 'truncated', 'text' => '', 'kind' => 'max_tokens', 'finish_reason' => $finishReason, 'message' => ''];
    }

    return ['status' => 'empty', 'text' => '', 'kind' => 'no_text', 'finish_reason' => $finishReason, 'message' => ''];
}

/**
 * Traduce el problema a un mensaje en español que explique qué pasó de verdad
 * y qué hacer, en vez de mostrar el texto crudo en inglés de Google.
 */
function translate_gemini_error($rawMessage) {
    switch (gemini_error_kind($rawMessage)) {
        case 'rate_limit':
            return "Tu clave gratuita de Gemini llegó a su límite de peticiones por ahora, o los servidores de Google están saturados. Espera un minuto y vuelve a intentar.";
        case 'invalid_key':
            return "Tu clave de Gemini no es válida. Ve a Ajustes → IA Personal y vuelve a vincularla (aistudio.google.com/apikey).";
        case 'model_gone':
            return "El modelo de IA solicitado ya no está disponible. Si esto persiste, avísale al administrador de la app.";
        default:
            return (string) $rawMessage;
    }
}

/**
 * Mensaje en español para las respuestas que NO son un error de Google pero
 * tampoco traen texto.
 */
function gemini_status_message($classified) {
    switch ($classified['status']) {
        case 'truncated':
            return "La IA se quedó sin espacio para escribir la respuesta. Intenta de nuevo con una pregunta más corta o más concreta.";
        case 'blocked':
            return "Google bloqueó esta respuesta por sus filtros de contenido. Reformula la pregunta e intenta de nuevo.";
        case 'error':
            return translate_gemini_error($classified['message']);
        case 'empty':
        default:
            return "La IA no devolvió ninguna respuesta. Espera unos segundos y vuelve a intentar.";
    }
}

/**
 * Quita el bloque de código markdown con el que los modelos suelen envolver
 * el JSON aunque se les pida "solo JSON puro" (```json ... ```).
 * Si no hay bloque, devuelve el texto tal cual (recortado).
 */
function gemini_strip_code_fence($text) {
    $clean = trim((string) $text);

    // Bloque completo: ```json\n...\n```
    if (preg_match('/^```[a-zA-Z]*\s*\n?(.*?)\n?\s*```$/s', $clean, $m)) {
        return trim($m[1]);
    }

    // Marcadores sueltos (bloque sin cerrar, o abierto a mitad del texto).
    $clean = preg_replace('/```[a-zA-Z]*/', '', $clean);
    return trim($clean);
}
