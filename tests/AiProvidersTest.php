<?php

use PHPUnit\Framework\TestCase;

/**
 * Cubre backend/lib/ai_providers.php: elegir proveedor, traducir las
 * peticiones de formato Gemini al de OpenAI (Groq), clasificar sus
 * respuestas y elegir los modelos con la capacidad que piden las tareas.
 */
final class AiProvidersTest extends TestCase
{
    // ---- proveedor ----

    public function testLaCabeceraManda(): void
    {
        $this->assertSame('groq', ai_provider_for_request('groq', 'AIzaSyAlgo'));
        $this->assertSame('gemini', ai_provider_for_request('GEMINI', 'gsk_algo'));
    }

    public function testSinCabeceraElPrefijoDeLaClaveDelata(): void
    {
        $this->assertSame('groq', ai_provider_for_request('', 'gsk_abc123'));
        $this->assertSame('gemini', ai_provider_for_request('', 'AIzaSyAbc123'));
        $this->assertSame('gemini', ai_provider_for_request(null, ''));
    }

    public function testUnProveedorDesconocidoCaeEnGemini(): void
    {
        $this->assertSame('gemini', ai_provider_for_request('openai', 'sk-algo'));
    }

    // ---- traducción de la petición ----

    private function payloadDeTexto(array $config = []): array
    {
        return ['contents' => [['role' => 'user', 'parts' => [['text' => 'Hola, responde en JSON']]]], 'generationConfig' => $config];
    }

    public function testUnMensajeDeTextoVaComoTextoPlano(): void
    {
        $body = ai_openai_payload_from_gemini($this->payloadDeTexto(), 'llama-3.3-70b-versatile');

        $this->assertSame('llama-3.3-70b-versatile', $body['model']);
        $this->assertSame([['role' => 'user', 'content' => 'Hola, responde en JSON']], $body['messages']);
    }

    public function testElRolModelPasaAAssistant(): void
    {
        $payload = ['contents' => [
            ['role' => 'user', 'parts' => [['text' => 'a']]],
            ['role' => 'model', 'parts' => [['text' => 'b']]],
        ]];

        $body = ai_openai_payload_from_gemini($payload, 'm');

        $this->assertSame(['user', 'assistant'], array_column($body['messages'], 'role'));
    }

    public function testUnaImagenSeEnviaComoDataUriJuntoAlTexto(): void
    {
        $payload = ['contents' => [['parts' => [
            ['text' => 'Lee este recibo'],
            ['inlineData' => ['mimeType' => 'image/png', 'data' => 'QUJD']],
        ]]]];

        $this->assertTrue(ai_payload_has_image($payload));
        $content = ai_openai_payload_from_gemini($payload, 'm')['messages'][0]['content'];

        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('image_url', $content[1]['type']);
        $this->assertSame('data:image/png;base64,QUJD', $content[1]['image_url']['url']);
    }

    public function testSinImagenNoSePideModeloConVision(): void
    {
        $this->assertFalse(ai_payload_has_image($this->payloadDeTexto()));
    }

    public function testElJsonPedidoSeTraduceAModoJson(): void
    {
        $body = ai_openai_payload_from_gemini($this->payloadDeTexto(['responseMimeType' => 'application/json', 'temperature' => 0.1]), 'm');

        $this->assertSame(['type' => 'json_object'], $body['response_format']);
        $this->assertSame(0.1, $body['temperature']);
    }

    public function testSinJsonNoSeFuerzaElModoJson(): void
    {
        $this->assertArrayNotHasKey('response_format', ai_openai_payload_from_gemini($this->payloadDeTexto(), 'm'));
    }

    // Groq cuenta contra el límite por minuto lo que se SOLICITA de salida.
    public function testLaSalidaSolicitadaSeLimitaParaElNivelGratuito(): void
    {
        $this->assertSame(AI_GROQ_MAX_OUTPUT_TOKENS, ai_openai_payload_from_gemini($this->payloadDeTexto(['maxOutputTokens' => 16384]), 'm')['max_completion_tokens']);
        $this->assertSame(1500, ai_openai_payload_from_gemini($this->payloadDeTexto(['maxOutputTokens' => 1500]), 'm')['max_completion_tokens']);
        $this->assertSame(AI_GROQ_MAX_OUTPUT_TOKENS, ai_openai_payload_from_gemini($this->payloadDeTexto(), 'm')['max_completion_tokens']);
    }

    public function testLosGptOssRazonanConElEsfuerzoMinimo(): void
    {
        $this->assertSame('low', ai_openai_payload_from_gemini($this->payloadDeTexto(), 'openai/gpt-oss-120b')['reasoning_effort']);
        $this->assertArrayNotHasKey('reasoning_effort', ai_openai_payload_from_gemini($this->payloadDeTexto(), 'llama-3.3-70b-versatile'));
    }

    // ---- clasificación de la respuesta ----

    private function ok(string $contenido, string $finish = 'stop'): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $contenido], 'finish_reason' => $finish]]];
    }

    public function testUnaRespuestaNormalEsOk(): void
    {
        $r = ai_openai_classify_response(200, $this->ok('  Todo bien  '));

        $this->assertSame('ok', $r['status']);
        $this->assertSame('Todo bien', $r['text']);
    }

    public function testQuitaElPensamientoInternoDeLosModelosQueLoDejanEnElTexto(): void
    {
        $r = ai_openai_classify_response(200, $this->ok("<think>calculando...\nmuchas cosas</think>\nRespuesta final"));

        $this->assertSame('Respuesta final', $r['text']);
    }

    public function testSiSoloHabiaPensamientoNoEsUnaRespuestaValida(): void
    {
        $r = ai_openai_classify_response(200, $this->ok('<think>solo pienso</think>', 'length'));

        $this->assertSame('truncated', $r['status']);
    }

    public function testDetectaElFiltroDeContenido(): void
    {
        $this->assertSame('blocked', ai_openai_classify_response(200, $this->ok('', 'content_filter'))['status']);
    }

    public function testUnaRespuestaVaciaSinMotivoNoSeTomaComoValida(): void
    {
        $this->assertSame('empty', ai_openai_classify_response(200, $this->ok('', 'stop'))['status']);
        $this->assertSame('empty', ai_openai_classify_response(200, null)['status']);
    }

    public function testClaveInvalida(): void
    {
        $r = ai_openai_classify_response(401, ['error' => ['message' => 'Invalid API Key', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']]);

        $this->assertSame('invalid_key', $r['kind']);
        $mensaje = gemini_status_message($r);
        $this->assertStringContainsString('clave de Groq', $mensaje);
        $this->assertStringContainsString('console.groq.com/keys', $mensaje);
    }

    public function testModeloInexistenteODadoDeBaja(): void
    {
        foreach ([
            [404, 'model_not_found', 'The model `x` does not exist or you do not have access to it.'],
            [400, 'model_decommissioned', 'The model `x` has been decommissioned and is no longer supported.'],
        ] as [$http, $code, $mensaje]) {
            $r = ai_openai_classify_response($http, ['error' => ['message' => $mensaje, 'code' => $code]]);
            $this->assertSame('model_gone', $r['kind'], $mensaje);
        }
    }

    public function testLimitePorMinutoUsaLosSegundosQueDiceGroq(): void
    {
        $r = ai_openai_classify_response(429, ['error' => [
            'message' => 'Rate limit reached for model `llama-3.3-70b-versatile` in organization `org_1` service tier `on_demand` on tokens per minute (TPM): Limit 12000, Used 11000, Requested 3000. Please try again in 8.5s.',
            'type' => 'tokens', 'code' => 'rate_limit_exceeded',
        ]]);

        $this->assertSame('rate_limit', $r['kind']);
        $this->assertSame('minute', gemini_rate_limit_detail($r));
        $this->assertSame(9, $r['retry_seconds']);
        $this->assertStringContainsString('Espera 9 segundos', gemini_status_message($r));
    }

    public function testCupoDiarioDeGroqIndicaCuantoFalta(): void
    {
        $r = ai_openai_classify_response(429, ['error' => [
            'message' => 'Rate limit reached for model `x` on tokens per day (TPD): Limit 100000, Used 99000, Requested 3000. Please try again in 7m12.5s.',
            'code' => 'rate_limit_exceeded',
        ]]);

        $this->assertSame('daily', gemini_rate_limit_detail($r));
        $this->assertSame(433, $r['retry_seconds']);
        $mensaje = gemini_status_message($r);
        $this->assertStringContainsString('cupo diario', $mensaje);
        $this->assertStringContainsString('8 minuto', $mensaje);
        $this->assertStringContainsString('Detalle de Groq', $mensaje);
        $this->assertStringNotContainsString('Pacífico', $mensaje, 'Esa regla de reinicio es de Google, no de Groq.');
    }

    public function testPeticionDemasiadoGrandeSeDistingueDeUnLimite(): void
    {
        $r = ai_openai_classify_response(413, ['error' => [
            'message' => 'Request too large for model `openai/gpt-oss-120b` on tokens per minute (TPM): Limit 8000, Requested 9500, please reduce your message size and try again.',
            'type' => 'tokens', 'code' => 'rate_limit_exceeded',
        ]]);

        $this->assertSame('rate_limit', $r['kind']);
        $this->assertSame('too_large', gemini_rate_limit_detail($r));
        $this->assertStringContainsString('más grande', gemini_status_message($r));
    }

    public function testUnServidorCaidoSinCuerpoUtilEsSobrecarga(): void
    {
        $r = ai_openai_classify_response(503, null);

        $this->assertSame('rate_limit', $r['kind']);
        $this->assertSame('overloaded', gemini_rate_limit_detail($r));
        $this->assertStringContainsString('saturados', gemini_status_message($r));
    }

    public function testAnalisisDeSegundosDeEspera(): void
    {
        $this->assertSame(5, ai_parse_retry_seconds('Please try again in 4.2s.'));
        $this->assertSame(83, ai_parse_retry_seconds('Please try again in 1m22.4s.'));
        $this->assertSame(420, ai_parse_retry_seconds('try again in 7m'));
        $this->assertSame(3660, ai_parse_retry_seconds('try again in 1h1m'));
        $this->assertSame(1, ai_parse_retry_seconds('try again in 250ms'));
        $this->assertNull(ai_parse_retry_seconds('sin pista de espera'));
    }

    public function testLosMensajesDeGeminiSiguenIgual(): void
    {
        $r = gemini_classify_response(['error' => ['message' => 'API key not valid. Please pass a valid API key.']]);

        $mensaje = gemini_status_message($r);
        $this->assertStringContainsString('clave de Gemini', $mensaje);
        $this->assertStringContainsString('aistudio.google.com/apikey', $mensaje);
    }

    // ---- elección de modelos de Groq ----

    private function listadoGroq(): array
    {
        $ids = [
            'llama-3.1-8b-instant',
            'llama-3.3-70b-versatile',
            'openai/gpt-oss-120b',
            'openai/gpt-oss-20b',
            'gemma2-9b-it',
            'whisper-large-v3',
            'whisper-large-v3-turbo',
            'playai-tts',
            'meta-llama/llama-guard-4-12b',
            'meta-llama/llama-4-scout-17b-16e-instruct',
            'meta-llama/llama-4-maverick-17b-128e-instruct',
            'qwen/qwen3-32b',
            'moonshotai/kimi-k2-instruct',
            'groq/compound',
        ];
        return ['data' => array_map(fn($id) => ['id' => $id, 'active' => true], $ids)];
    }

    public function testEligeLosModelosMasCapacesPrimero(): void
    {
        $this->assertSame(
            ['openai/gpt-oss-120b', 'moonshotai/kimi-k2-instruct', 'meta-llama/llama-4-maverick-17b-128e-instruct', 'llama-3.3-70b-versatile'],
            ai_groq_pick_models($this->listadoGroq())
        );
    }

    public function testDescartaLosModelosPequenosYLosQueNoSonDeChat(): void
    {
        $elegidos = ai_groq_pick_models($this->listadoGroq());

        foreach (['llama-3.1-8b-instant', 'gemma2-9b-it', 'whisper-large-v3', 'playai-tts', 'meta-llama/llama-guard-4-12b', 'groq/compound'] as $descartado) {
            $this->assertNotContains($descartado, $elegidos, $descartado);
        }
    }

    public function testParaImagenesSoloQuedanLosModelosConVision(): void
    {
        $this->assertSame(
            ['meta-llama/llama-4-maverick-17b-128e-instruct', 'meta-llama/llama-4-scout-17b-16e-instruct'],
            ai_groq_pick_models($this->listadoGroq(), true)
        );
    }

    public function testUnModeloDesconocidoGrandeEntraDespuesDeLosConocidos(): void
    {
        $listado = ['data' => [
            ['id' => 'nuevo-modelo-405b-chat'],
            ['id' => 'llama-3.3-70b-versatile'],
            ['id' => 'nuevo-modelo-chico-12b'],
            ['id' => 'modelo-sin-tamano'],
        ]];

        $this->assertSame(['llama-3.3-70b-versatile', 'nuevo-modelo-405b-chat'], ai_groq_pick_models($listado));
    }

    public function testLosModelosInactivosSeSaltan(): void
    {
        $listado = ['data' => [['id' => 'openai/gpt-oss-120b', 'active' => false], ['id' => 'llama-3.3-70b-versatile', 'active' => true]]];

        $this->assertSame(['llama-3.3-70b-versatile'], ai_groq_pick_models($listado));
    }

    public function testSinModelosCapacesNoSeInventaNinguno(): void
    {
        $this->assertSame([], ai_groq_pick_models(['data' => [['id' => 'llama-3.1-8b-instant'], ['id' => 'gemma2-9b-it']]]));
        $this->assertSame([], ai_groq_pick_models(null));
    }

    public function testNuncaSeEligenMasDeCuatro(): void
    {
        $data = [];
        foreach ([100, 110, 120, 130, 140, 150] as $size) {
            $data[] = ['id' => "modelo-{$size}b"];
        }

        $this->assertCount(4, ai_groq_pick_models(['data' => $data]));
    }

    public function testElRespaldoSinDescubrimientoRespetaLaVision(): void
    {
        $this->assertContains('llama-3.3-70b-versatile', ai_groq_fallback_models());
        $this->assertNotContains('llama-3.3-70b-versatile', ai_groq_fallback_models(true));
        foreach (ai_groq_fallback_models(true) as $modelo) {
            $this->assertMatchesRegularExpression(AI_GROQ_VISION, $modelo);
        }
    }
}
