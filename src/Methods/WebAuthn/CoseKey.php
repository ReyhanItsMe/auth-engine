<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Methods\WebAuthn;

use ReyhanItsMe\AuthEngine\Exceptions\WebAuthnException;

/**
 * Converts a COSE public key (RFC 9052) into a PEM key usable by OpenSSL.
 * Supported: ES256 (P-256) and RS256.
 */
final class CoseKey
{
    public const ES256 = -7;
    public const RS256 = -257;

    private function __construct()
    {
    }

    /** @param array<array-key, mixed> $cose decoded CBOR map */
    public static function toPem(array $cose): string
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;

        if ($kty === 2 && $alg === self::ES256) {
            $x = $cose[-2] ?? null;
            $y = $cose[-3] ?? null;
            if (($cose[-1] ?? null) !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new WebAuthnException('Invalid ES256 public key.');
            }

            // SubjectPublicKeyInfo header for id-ecPublicKey + prime256v1, then the uncompressed point.
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;

            return self::pem($der);
        }

        if ($kty === 3 && $alg === self::RS256) {
            $n = $cose[-1] ?? null;
            $e = $cose[-2] ?? null;
            if (!is_string($n) || !is_string($e) || strlen($n) < 256 || $e === '') {
                throw new WebAuthnException('Invalid RS256 public key.');
            }

            $rsaKey = self::der(0x30, self::integer($n) . self::integer($e));
            $der = self::der(0x30, hex2bin('300d06092a864886f70d0101010500') . self::der(0x03, "\x00" . $rsaKey));

            return self::pem($der);
        }

        throw new WebAuthnException('Unsupported passkey algorithm (only ES256 and RS256 are accepted).');
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $content;
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $content;
    }
}
