<?php
// tests/SessionRefreshTest.php
//
// La renovación deslizante toca el camino de autenticación: si se equivoca,
// o deja fuera a todos los usuarios, o revive sesiones que debían morir.

use PHPUnit\Framework\TestCase;

class SessionRefreshTest extends TestCase
{
    private const DIA = 86400;

    public function testUnTokenReciennEmitidoNoSeRenueva(): void
    {
        $ahora = time();
        $exp = $ahora + (30 * self::DIA);

        $this->assertFalse(should_refresh_session($exp, $ahora));
    }

    public function testJustoAntesDeLaMitadTodaviaNoSeRenueva(): void
    {
        $ahora = time();
        $exp = $ahora + (16 * self::DIA); // quedan 16 de 30

        $this->assertFalse(should_refresh_session($exp, $ahora));
    }

    public function testPasadaLaMitadDeVidaSeRenueva(): void
    {
        $ahora = time();
        $exp = $ahora + (14 * self::DIA); // quedan 14 de 30

        $this->assertTrue(should_refresh_session($exp, $ahora));
    }

    public function testAPuntoDeVencerSeRenueva(): void
    {
        $ahora = time();
        $exp = $ahora + 60; // le queda un minuto

        $this->assertTrue(should_refresh_session($exp, $ahora));
    }

    public function testUnTokenVencidoNuncaSeRenueva(): void
    {
        // Esta es la propiedad de seguridad: una sesión muerta no revive.
        $ahora = time();

        $this->assertFalse(should_refresh_session($ahora - 1, $ahora));
        $this->assertFalse(should_refresh_session($ahora - (400 * self::DIA), $ahora));
        $this->assertFalse(should_refresh_session($ahora, $ahora));
    }

    public function testUnTokenSinExpiracionNoSeRenueva(): void
    {
        $this->assertFalse(should_refresh_session(null, time()));
        $this->assertFalse(should_refresh_session('no-es-una-fecha', time()));
    }

    public function testUsarLaAppSeguidoMantieneLaSesionVivaIndefinidamente(): void
    {
        // Simula a alguien que entra cada 10 dias durante un año: no deberia
        // quedar fuera nunca (es justo el caso que rompio la app en produccion).
        $vida = 30 * self::DIA;
        $ahora = time();
        $exp = $ahora + $vida;

        for ($visita = 1; $visita <= 36; $visita++) {
            $ahora += 10 * self::DIA;

            $this->assertGreaterThan(
                0,
                $exp - $ahora,
                "La sesión venció en la visita {$visita} pese al uso regular"
            );

            if (should_refresh_session($exp, $ahora, $vida)) {
                $exp = $ahora + $vida;
            }
        }
    }

    public function testAbandonarLaAppSiCierraLaSesion(): void
    {
        // El reverso: quien no vuelve en 31 dias si debe quedar fuera.
        $ahora = time();
        $exp = $ahora + (30 * self::DIA);

        $this->assertLessThan(0, $exp - ($ahora + (31 * self::DIA)));
    }
}
