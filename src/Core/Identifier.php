<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

/**
 * Normalises and validates login identifiers (email, username, phone_number).
 */
final class Identifier
{
    private function __construct()
    {
    }

    /** @return string|null the normalised value, or null when it is not valid for $field */
    public static function normalize(string $field, string $value): ?string
    {
        $value = trim($value);

        switch ($field) {
            case 'email':
                $value = mb_strtolower($value);

                return strlen($value) <= 255 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;

            case 'username':
                $value = mb_strtolower($value);

                return preg_match('/^[a-z0-9](?:[a-z0-9._-]{1,30})[a-z0-9]$/', $value) === 1 ? $value : null;

            case 'phone_number':
                $value = (string) preg_replace('/[\s().-]+/', '', $value);

                return preg_match('/^\+?[1-9]\d{6,14}$/', $value) === 1 ? $value : null;
        }

        return null;
    }

    /**
     * Guess which configured field(s) a login input may refer to.
     *
     * @param list<string> $fields configured identifiers, in priority order
     * @return array<string, string> field => normalised value
     */
    public static function candidates(string $input, array $fields): array
    {
        $candidates = [];

        foreach ($fields as $field) {
            if ($field === 'email' && !str_contains($input, '@')) {
                continue;
            }
            if ($field !== 'email' && str_contains($input, '@')) {
                continue;
            }

            $normalized = self::normalize($field, $input);
            if ($normalized !== null) {
                $candidates[$field] = $normalized;
            }
        }

        return $candidates;
    }
}
