<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

/**
 * Tiny environment reader so config files work without any framework helper.
 */
final class Env
{
    private function __construct()
    {
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    /**
     * Loads KEY=value pairs from a dotenv-style file into the process environment.
     * Variables that are already set are left alone. Returns the number of variables loaded.
     */
    public static function loadFile(string $path): int
    {
        if (!is_file($path) || !is_readable($path)) {
            return 0;
        }

        $loaded = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            [$key, $value] = [$m[1], $m[2]];

            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $value, $q) === 1) {
                $value = stripcslashes($q[1]);
            } elseif (preg_match("/^'([^']*)'/", $value, $q) === 1) {
                $value = $q[1];
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }

            if (isset($_ENV[$key]) || isset($_SERVER[$key]) || getenv($key) !== false) {
                continue;
            }

            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
            $loaded++;
        }

        return $loaded;
    }
}
