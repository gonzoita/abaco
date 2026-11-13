<?php
// tests/GeminiResponseTest.php
//
// Cubre la clase de fallo que rompió la IA en produccion tres veces seguidas:
// Gemini responde HTTP 200, SIN campo 'error', y aun asi no trae texto. El
// codigo antiguo solo miraba si venia 'error', asi que esas respuestas pasaban
// como buenas y el usuario recibia un mensaje generico imposible de diagnosticar.

use PHPUnit\Framework\TestCase;

class GeminiResponseTest extends TestCase
{
    // --- Respuesta normal ---

    public function testExtraeElTextoDeUnaRespuestaNormal(): void
    {
        $decoded = [
            'candidates' => [
                ['content' => ['parts' => [['text' => 'Hola, soy Abaco.']]], 'finishReason' => 'STOP']
            ]
        ];

        $result = gemini_classify_response($decoded);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('Hola, soy Abaco.', $result['text']);
    }

    public function testUneVariasPartesDeTexto(): void
    {
        $decoded = [
            'candidates' => [
                ['content' => ['parts' => [['text' => 'Parte uno. '], ['text' => 'Parte dos.']]]]
            ]
        ];

        $this->assertSame('Parte uno. Parte dos.', gemini_classify_response($decoded)['text']);
    }

    public function testIgnoraLasPartesDeRazonamientoInterno(): void
    {
        // Los modelos que "piensan" marcan su razonamiento con thought=true.
        // Eso no es la respuesta para el usuario y no debe filtrarse a la app.
        $decoded = [
            'candidates' => [
                ['content' => ['parts' => [
                    ['text' => 'El usuario pregunta por su saldo...', 'thought' => true],
                    ['text' => 'Tu saldo es 100.']
                ]]]
            ]
        ];

        $this->assertSame('Tu saldo es 100.', gemini_classify_response($decoded)['text']);
    }

    // --- EL BUG: 200 OK, sin error, sin texto ---

    public function testDetectaQueSeAgotaronLosTokensAntesDeResponder(): void
    {
        // Caso exacto que rompia la app: el modelo gasta el presupuesto de
        // tokens razonando y termina sin escribir nada. No hay campo 'error'.
        $decoded = [
            'candidates' => [
                ['content' => ['role' => 'model'], 'finishReason' => 'MAX_TOKENS']
            ]
        ];

        $result = gemini_classify_response($decoded);

        $this->assertSame('truncated', $result['status']);
        $this->assertSame('', $result['text']);
        $this->assertStringContainsString('sin espacio', gemini_status_message($result));
    }

    public function testDetectaContenidoBloqueadoPorLosFiltrosDeGoogle(): void
    {
        $decoded = ['promptFeedback' => ['blockReason' => 'SAFETY']];

        $result = gemini_classify_response($decoded);

        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsString('bloque', mb_strtolower(gemini_status_message($result)));
    }

    public function testDetectaRespuestaBloqueadaPorFinishReason(): void
    {
        $decoded = [
            'candidates' => [['content' => ['parts' => []], 'finishReason' => 'SAFETY']]
        ];

        $this->assertSame('blocked', gemini_classify_response($decoded)['status']);
    }

    public function testRespuestaVaciaSinMotivoNoSeTomaComoValida(): void
    {
        $result = gemini_classify_response(['candidates' => []]);

        $this->assertSame('empty', $result['status']);
        $this->assertNotSame('ok', $result['status']);
    }

    public function testRespuestaNoParseableNoSeTomaComoValida(): void
    {
        // json_decode devuelve null cuando Google (o el servidor) responde algo
        // que no es JSON.
        $this->assertSame('empty', gemini_classify_response(null)['status']);
    }

    // --- Errores explicitos de Google ---

    public function testClasificaClaveInvalida(): void
    {
        $decoded = ['error' => ['message' => 'API key not valid. Please pass a valid API key.']];

        $result = gemini_classify_response($decoded);

        $this->assertSame('error', $result['status']);
        $this->assertSame('invalid_key', $result['kind']);
        $this->assertStringContainsString('Ajustes', gemini_status_message($result));
    }

    public function testClasificaLimiteDePeticiones(): void
    {
        foreach ([
            'This model is currently experiencing high demand.',
            'Resource has been exhausted (e.g. check quota).',
            'The service is currently overloaded. 503',
        ] as $mensaje) {
            $result = gemini_classify_response(['error' => ['message' => $mensaje]]);
            $this->assertSame('rate_limit', $result['kind'], $mensaje);
            $this->assertStringContainsString('Espera', gemini_status_message($result), $mensaje);
        }
    }

    private function errorDeCuota(string $mensaje, array $violaciones, ?string $retryDelay = null, string $status = 'RESOURCE_EXHAUSTED'): array
    {
        $details = [['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => $violaciones]];
        if ($retryDelay !== null) {
            $details[] = ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retryDelay];
        }
        return ['error' => ['code' => 429, 'message' => $mensaje, 'status' => $status, 'details' => $details]];
    }

    // Caso que no se distinguía: la clave gratuita NO tiene cupo para el
    // modelo (límite 0). El mensaje decía "espera un minuto" y esperar jamás
    // lo arregla.
    public function testUnCupoGratuitoDeCeroNoDiceQueEsperes(): void
    {
        $result = gemini_classify_response($this->errorDeCuota(
            'You exceeded your current quota, please check your plan and billing details.',
            [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier', 'quotaValue' => '0']]
        ));

        $this->assertSame('rate_limit', $result['kind']);
        $this->assertSame('zero', gemini_rate_limit_detail($result));
        $mensaje = gemini_status_message($result);
        $this->assertStringContainsString('no tiene cupo', $mensaje);
        $this->assertStringNotContainsString('Espera un minuto', $mensaje);
    }

    public function testElLimiteDentroDelTextoDelMensajeTambienSeDetectaComoCero(): void
    {
        $result = gemini_classify_response(['error' => ['message' => 'Quota exceeded for metric: generate_content_free_tier_requests, limit: 0, model: gemini-x']]);

        $this->assertSame('zero', gemini_rate_limit_detail($result));
    }

    public function testDetectaQueSeAgotoElCupoDelDia(): void
    {
        $result = gemini_classify_response($this->errorDeCuota(
            'You exceeded your current quota.',
            [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier', 'quotaValue' => '20']]
        ));

        $this->assertSame('daily', gemini_rate_limit_detail($result));
        $this->assertStringContainsString('hoy', gemini_status_message($result));
    }

    public function testElLimitePorMinutoUsaLosSegundosQueIndicaGoogle(): void
    {
        $result = gemini_classify_response($this->errorDeCuota(
            'You exceeded your current quota. Please retry in 37.5s.',
            [['quotaId' => 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier', 'quotaValue' => '10']],
            '37s'
        ));

        $this->assertSame('minute', gemini_rate_limit_detail($result));
        $this->assertSame(37, $result['retry_seconds']);
        $this->assertStringContainsString('Espera 37 segundos', gemini_status_message($result));
    }

    public function testLosSegundosDeEsperaTambienSeLeenDelTextoDelMensaje(): void
    {
        $result = gemini_classify_response(['error' => ['message' => 'Rate limit hit (PerMinute). Please retry in 12.2s.']]);

        $this->assertSame(13, $result['retry_seconds']);
    }

    public function testUnServidorSaturadoSeDistingueDeUnaCuotaAgotada(): void
    {
        $result = gemini_classify_response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'The model is overloaded. Please try again later.']]);

        $this->assertSame('overloaded', gemini_rate_limit_detail($result));
        $this->assertStringContainsString('saturados', gemini_status_message($result));
    }

    public function testElMensajeIncluyeElDetalleOriginalDeGoogleRecortado(): void
    {
        $largo = 'Quota exceeded for metric: x. ' . str_repeat('detalle ', 80);
        $result = gemini_classify_response(['error' => ['message' => $largo]]);

        $mensaje = gemini_status_message($result);

        $this->assertStringContainsString('Detalle de Google: Quota exceeded for metric: x.', $mensaje);
        $this->assertStringContainsString('…', $mensaje);
        $this->assertLessThan(700, mb_strlen($mensaje));
    }

    public function testLosErroresQueNoSonDeLimiteNoLlevanDetalleExtra(): void
    {
        $result = gemini_classify_response(['error' => ['message' => 'API key not valid. Please pass a valid API key.']]);

        $this->assertStringNotContainsString('Detalle de Google', gemini_status_message($result));
    }

    // ---- elección de modelos ----

    private function listadoDeModelos(): array
    {
        $gen = ['generateContent', 'countTokens'];
        return ['models' => [
            ['name' => 'models/gemini-3.8-flash', 'supportedGenerationMethods' => $gen],
            ['name' => 'models/gemini-3.6-flash', 'supportedGenerationMethods' => $gen],
            ['name' => 'models/gemini-3.10-flash', 'supportedGenerationMethods' => $gen],
            ['name' => 'models/gemini-3.8-flash-lite', 'supportedGenerationMethods' => $gen],
            ['name' => 'models/gemini-3.8-flash-preview-1105', 'supportedGenerationMethods' => $gen],
            ['name' => 'models/gemini-3.8-flash-image', 'supportedGenerationMethods' => $gen],
            ['name' => 'models/gemini-3.8-pro', 'supportedGenerationMethods' => $gen],
            ['name' => 'models/gemini-3.9-flash', 'supportedGenerationMethods' => ['embedContent']],
            ['name' => 'models/embedding-001', 'supportedGenerationMethods' => ['embedContent']],
        ]];
    }

    public function testElegeLosFlashEstablesDelMasNuevoAlMasViejo(): void
    {
        $this->assertSame(
            ['gemini-3.10-flash', 'gemini-3.8-flash', 'gemini-3.6-flash', 'gemini-3.8-flash-lite'],
            gemini_pick_models($this->listadoDeModelos()),
            '3.10 es más nuevo que 3.8; los preview, imagen, pro y los que no generan texto se descartan.'
        );
    }

    public function testNoElegiraMasDeTresFlashNiMasDeUnLite(): void
    {
        $gen = ['generateContent'];
        $listado = ['models' => []];
        foreach (['3.1', '3.2', '3.3', '3.4'] as $v) {
            $listado['models'][] = ['name' => "models/gemini-{$v}-flash", 'supportedGenerationMethods' => $gen];
            $listado['models'][] = ['name' => "models/gemini-{$v}-flash-lite", 'supportedGenerationMethods' => $gen];
        }

        $this->assertSame(
            ['gemini-3.4-flash', 'gemini-3.3-flash', 'gemini-3.2-flash', 'gemini-3.4-flash-lite'],
            gemini_pick_models($listado)
        );
    }

    public function testUnaRespuestaInvalidaNoDescubreModelos(): void
    {
        $this->assertSame([], gemini_pick_models(null));
        $this->assertSame([], gemini_pick_models(['error' => ['message' => 'API key not valid']]));
    }

    public function testElOrdenDePruebaEmpiezaPorElAliasYSigueConLosDescubiertos(): void
    {
        $cadena = gemini_model_chain(gemini_pick_models($this->listadoDeModelos()));

        $this->assertSame(
            ['gemini-flash-latest', 'gemini-3.10-flash', 'gemini-3.8-flash', 'gemini-3.6-flash', 'gemini-3.8-flash-lite', 'gemini-flash-lite-latest'],
            $cadena
        );
    }

    public function testSiNoSePuedeDescubrirNadaQuedanLosNombresConocidos(): void
    {
        $cadena = gemini_model_chain([]);

        $this->assertSame('gemini-flash-latest', $cadena[0]);
        $this->assertContains('gemini-flash-lite-latest', $cadena);
        $this->assertContains('gemini-3.6-flash', $cadena);
        $this->assertCount(6, $cadena);
        $this->assertSame(array_unique($cadena), $cadena, 'Sin repetidos.');
    }

    public function testClasificaModeloRetirado(): void
    {
        // Google retira modelos con el tiempo; hay que poder pasar al siguiente.
        $decoded = ['error' => ['message' => 'models/gemini-2.0-flash is not found for API version v1beta']];

        $this->assertSame('model_gone', gemini_classify_response($decoded)['kind']);
    }

    public function testClasificaParametroDeRazonamientoNoSoportado(): void
    {
        // Google cambio el nombre de este campo entre generaciones
        // (thinking_budget -> thinking_level). Si un modelo lo rechaza, hay que
        // reintentar sin el en vez de dar la peticion por perdida.
        $decoded = ['error' => ['message' => 'Unknown name "thinkingLevel": Cannot find field.']];

        $this->assertSame('bad_thinking', gemini_classify_response($decoded)['kind']);
    }

    public function testUnErrorDesconocidoConservaElMensajeOriginal(): void
    {
        $decoded = ['error' => ['message' => 'Algo raro paso']];

        $result = gemini_classify_response($decoded);

        $this->assertSame('other', $result['kind']);
        $this->assertSame('Algo raro paso', gemini_status_message($result));
    }

    // --- Limpieza del JSON que devuelve el modelo ---

    public function testQuitaElBloqueMarkdownAlrededorDelJson(): void
    {
        $texto = "```json\n{\"monto\": 5000}\n```";

        $this->assertSame('{"monto": 5000}', gemini_strip_code_fence($texto));
    }

    public function testQuitaElBloqueMarkdownSinEtiquetaDeLenguaje(): void
    {
        $this->assertSame('{"a": 1}', gemini_strip_code_fence("```\n{\"a\": 1}\n```"));
    }

    public function testDejaIntactoElJsonSinBloqueMarkdown(): void
    {
        $this->assertSame('{"a": 1}', gemini_strip_code_fence('  {"a": 1}  '));
    }

    public function testElResultadoLimpioSiempreEsJsonParseable(): void
    {
        foreach (["```json\n{\"x\": 1}\n```", "{\"x\": 1}", "```\n{\"x\": 1}"] as $variante) {
            $this->assertSame(['x' => 1], json_decode(gemini_strip_code_fence($variante), true), $variante);
        }
    }
}
