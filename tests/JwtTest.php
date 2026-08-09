<?php

use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    public function testEncodeThenDecodeReturnsOriginalPayload(): void
    {
        $payload = ['user_id' => 42, 'email' => 'diego@example.com', 'exp' => time() + 3600];
        $token = JWT::encode($payload, 'secreto-de-prueba');

        $decoded = JWT::decode($token, 'secreto-de-prueba');

        $this->assertSame(42, $decoded['user_id']);
        $this->assertSame('diego@example.com', $decoded['email']);
    }

    public function testDecodeRejectsTokenSignedWithWrongSecret(): void
    {
        $token = JWT::encode(['user_id' => 1], 'secreto-correcto');

        $decoded = JWT::decode($token, 'secreto-incorrecto');

        $this->assertFalse($decoded, 'Un token firmado con otro secreto nunca debe validar.');
    }

    public function testDecodeRejectsExpiredToken(): void
    {
        $token = JWT::encode(['user_id' => 1, 'exp' => time() - 10], 'secreto');

        $decoded = JWT::decode($token, 'secreto');

        $this->assertFalse($decoded, 'Un token con exp en el pasado debe rechazarse.');
    }

    public function testDecodeRejectsMalformedToken(): void
    {
        $this->assertFalse(JWT::decode('esto-no-es-un-jwt', 'secreto'));
        $this->assertFalse(JWT::decode('solo.dospartes', 'secreto'));
    }

    public function testDecodeRejectsTamperedPayload(): void
    {
        $token = JWT::encode(['user_id' => 1, 'role' => 'user'], 'secreto');
        $parts = explode('.', $token);

        // Simula un atacante cambiando el payload para volverse admin sin
        // conocer el secreto: la firma ya no debe coincidir.
        $tamperedPayload = rtrim(strtr(base64_encode(json_encode(['user_id' => 1, 'role' => 'admin'])), '+/', '-_'), '=');
        $tamperedToken = $parts[0] . '.' . $tamperedPayload . '.' . $parts[2];

        $this->assertFalse(JWT::decode($tamperedToken, 'secreto'));
    }
}
