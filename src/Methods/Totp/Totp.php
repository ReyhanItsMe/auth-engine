<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Methods\Totp;

/**
 * RFC 6238 time-based one-time passwords (HMAC-SHA1, as used by
 * Google Authenticator, Authy, 1Password, ...).
 */
final class Totp
{
    private function __construct()
    {
    }

    /** New Base32 secret (160 bits by default). */
    public static function generateSecret(int $bytes = 20): string
    {
        return Base32::encode(random_bytes(max(16, $bytes)));
    }

    public static function code(string $secret, int $time, int $period = 30, int $digits = 6): string
    {
        return self::atStep($secret, intdiv($time, max(1, $period)), $digits);
    }

    public static function atStep(string $secret, int $step, int $digits = 6): string
    {
        $key = Base32::decode($secret) ?? '';
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * @param int $window accepted drift in periods before/after $time
     * @return int|null the time step the code belongs to, or null when it is wrong
     */
    public static function matchingStep(string $secret, string $code, int $time, int $period = 30, int $digits = 6, int $window = 1): ?int
    {
        if (strlen($code) !== $digits || !ctype_digit($code) || Base32::decode($secret) === null) {
            return null;
        }

        $current = intdiv($time, max(1, $period));
        $match = null;

        // Check every step (no early exit) to keep the timing independent of the match position.
        for ($step = $current - $window; $step <= $current + $window; $step++) {
            if ($step >= 0 && hash_equals(self::atStep($secret, $step, $digits), $code)) {
                $match = $step;
            }
        }

        return $match;
    }

    /** otpauth:// URI to render as a QR code for authenticator apps. */
    public static function uri(string $issuer, string $account, string $secret, int $digits = 6, int $period = 30): string
    {
        $issuer = str_replace(':', ' ', $issuer);
        $account = str_replace(':', ' ', $account);

        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => $digits,
            'period' => $period,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
