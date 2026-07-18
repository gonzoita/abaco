<?php
// C:\laragon\www\control-finanzas\backend\config\jwt.php

class JWT {
    /**
     * Codifica un payload en un token JWT.
     */
    public static function encode(array $payload, string $secret): string {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Decodifica y valida un token JWT. Retorna el payload o false si es inválido.
     */
    public static function decode(string $token, string $secret) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        
        list($headerUrl, $payloadUrl, $signatureUrl) = $parts;
        
        $signature = self::base64UrlDecode($signatureUrl);
        $expectedSignature = hash_hmac('sha256', $headerUrl . "." . $payloadUrl, $secret, true);
        
        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }
        
        $payload = json_decode(self::base64UrlDecode($payloadUrl), true);
        
        // Verificar expiración si está definida
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }
        
        return $payload;
    }

    private static function base64UrlEncode(string $data): string {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
