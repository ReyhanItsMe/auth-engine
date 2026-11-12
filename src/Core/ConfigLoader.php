<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;

/**
 * Builds a validated Config from the package defaults deep-merged
 * with user overrides (array or path to a PHP file returning an array).
 */
final class ConfigLoader
{
    public const IDENTIFIERS = ['email', 'username', 'phone_number'];
    public const HASH_ALGOS = ['argon2id', 'bcrypt'];
    public const ADAPTERS = ['auto', 'native', 'laravel'];

    /** Drivers that sign tokens and therefore need "app.key". */
    private const KEY_DEPENDENT_DRIVERS = ['magic_link', 'otp', 'totp'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>|string|null $source overrides array, config file path, or null for pure defaults
     */
    public static function load(array|string|null $source = null): Config
    {
        $overrides = match (true) {
            $source === null => [],
            is_string($source) => self::readFile($source),
            default => $source,
        };

        $items = self::merge(self::defaults(), $overrides);
        self::validate($items);

        return new Config($items);
    }

    public static function defaultPath(): string
    {
        return dirname(__DIR__, 2) . '/config/auth-engine.php';
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return self::readFile(self::defaultPath());
    }

    /**
     * Deep merge: associative arrays are merged recursively,
     * lists and scalars from $overrides replace the base value.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     * @return array<array-key, mixed>
     */
    public static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !self::isList($value)
                && !self::isList($base[$key])
            ) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /** @param array<string, mixed> $items */
    public static function validate(array $items): void
    {
        $adapter = $items['adapter'] ?? 'auto';
        if (!in_array($adapter, self::ADAPTERS, true)) {
            throw new ConfigurationException(sprintf(
                'Invalid "adapter" [%s]. Allowed: %s.',
                self::stringify($adapter),
                implode(', ', self::ADAPTERS),
            ));
        }

        $identifiers = $items['identifiers'] ?? null;
        if (!is_array($identifiers) || $identifiers === []) {
            throw new ConfigurationException('"identifiers" must be a non-empty list.');
        }
        foreach ($identifiers as $identifier) {
            if (!in_array($identifier, self::IDENTIFIERS, true)) {
                throw new ConfigurationException(sprintf(
                    'Invalid identifier [%s]. Allowed: %s.',
                    self::stringify($identifier),
                    implode(', ', self::IDENTIFIERS),
                ));
            }
        }

        $algo = $items['hashing']['algo'] ?? null;
        if (!in_array($algo, self::HASH_ALGOS, true)) {
            throw new ConfigurationException(sprintf(
                'Invalid "hashing.algo" [%s]. Allowed: %s.',
                self::stringify($algo),
                implode(', ', self::HASH_ALGOS),
            ));
        }

        $lifetime = $items['session']['lifetime'] ?? null;
        if (!is_int($lifetime) || $lifetime < 0) {
            throw new ConfigurationException('"session.lifetime" must be an integer >= 0 (minutes).');
        }

        $sessionKey = $items['session']['key'] ?? null;
        if (!is_string($sessionKey) || $sessionKey === '') {
            throw new ConfigurationException('"session.key" must be a non-empty string.');
        }

        $minLength = $items['password']['min_length'] ?? null;
        if (!is_int($minLength) || $minLength < 6) {
            throw new ConfigurationException('"password.min_length" must be an integer >= 6.');
        }

        $digits = $items['drivers']['otp']['digits'] ?? 6;
        if (!is_int($digits) || $digits < 4 || $digits > 8) {
            throw new ConfigurationException('"drivers.otp.digits" must be an integer between 4 and 8.');
        }

        $drivers = $items['drivers'] ?? null;
        if (!is_array($drivers)) {
            throw new ConfigurationException('"drivers" must be an array.');
        }

        $key = $items['app']['key'] ?? null;
        foreach ($drivers as $name => $options) {
            if (!is_array($options) || !is_bool($options['enabled'] ?? null)) {
                throw new ConfigurationException(sprintf(
                    'Driver "%s" must be an array containing a boolean "enabled" flag.',
                    (string) $name,
                ));
            }

            if (
                $options['enabled']
                && in_array($name, self::KEY_DEPENDENT_DRIVERS, true)
                && (!is_string($key) || strlen($key) < 32)
            ) {
                throw new ConfigurationException(sprintf(
                    'Driver "%s" requires "app.key" (AUTH_ENGINE_KEY) with at least 32 characters.',
                    (string) $name,
                ));
            }
        }
    }

    /** @return array<string, mixed> */
    private static function readFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigurationException(sprintf('Config file [%s] does not exist or is not readable.', $path));
        }

        $items = (static fn (string $file): mixed => require $file)($path);

        if (!is_array($items)) {
            throw new ConfigurationException(sprintf('Config file [%s] must return an array.', $path));
        }

        return $items;
    }

    /** @param array<array-key, mixed> $value */
    private static function isList(array $value): bool
    {
        return $value !== [] && array_is_list($value);
    }

    private static function stringify(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
