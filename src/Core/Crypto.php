<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

use ReyhanItsMe\AuthEngine\Exceptions\AuthEngineException;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;

/**
 * Keyed hashing and authenticated encryption derived from "app.key".
 */
final class Crypto
{
    public function __construct(private readonly ?string $key)
    {
    }

    /** HMAC-SHA256 (hex). Used to store tokens and codes without keeping the plain value. */
    public function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, $this->derive('hmac'));
    }

    /** AES-256-GCM. Output: "v1:" + base64(iv | tag | ciphertext). */
    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->derive('encrypt'), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new AuthEngineException('Encryption failed.');
        }

        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $raw = str_starts_with($payload, 'v1:') ? base64_decode(substr($payload, 3), true) : false;

        if ($raw === false || strlen($raw) < 28) {
            throw new AuthEngineException('Malformed encrypted payload.');
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $this->derive('encrypt'),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
        );

        if ($plain === false) {
            throw new AuthEngineException('Decryption failed: wrong "app.key" or corrupted data.');
        }

        return $plain;
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return string|null null when the input is not valid base64url */
    public static function base64UrlDecode(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $text) !== 1) {
            return null;
        }

        $bytes = base64_decode(strtr(rtrim($text, '='), '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return self::base64UrlEncode(random_bytes(max(16, $bytes)));
    }

    private function derive(string $purpose): string
    {
        if ($this->key === null || strlen($this->key) < 32) {
            throw new ConfigurationException(
                '"app.key" (AUTH_ENGINE_KEY) with at least 32 characters is required for this feature.',
            );
        }

        return hash_hkdf('sha256', $this->key, 32, 'auth-engine:' . $purpose);
    }
}
