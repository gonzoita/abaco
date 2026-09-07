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
