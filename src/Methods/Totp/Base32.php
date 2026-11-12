<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Methods\Totp;

/** RFC 4648 Base32 (upper-case alphabet, no padding on output). */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private function __construct()
    {
    }

    public static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $bytes === '' ? '' : $out;
    }

    /** @return string|null null when the input contains characters outside the alphabet */
    public static function decode(string $text): ?string
    {
        $text = strtoupper(rtrim(str_replace([' ', '-'], '', $text), '='));
        if ($text === '' || strspn($text, self::ALPHABET) !== strlen($text)) {
            return null;
        }

        $bits = '';
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr((int) bindec($chunk));
            }
        }

        return $out;
    }
}
