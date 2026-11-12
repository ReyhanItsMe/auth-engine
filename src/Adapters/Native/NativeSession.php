<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Adapters\Native;

use ReyhanItsMe\AuthEngine\Contracts\SessionInterface;
use ReyhanItsMe\AuthEngine\Exceptions\AuthEngineException;

/**
 * Hardened wrapper around PHP's native session ($_SESSION).
 * If the host application already started a session, it is reused as-is.
 */
final class NativeSession implements SessionInterface
{
    /**
     * @param array{name?: string, path?: string, domain?: ?string, secure?: ?bool, http_only?: bool, same_site?: string} $cookie
     */
    public function __construct(private readonly array $cookie = [])
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent($file, $line)) {
            throw new AuthEngineException(sprintf(
                'Cannot start the session: output already started at %s:%d. Call Auth::boot() before sending output.',
                $file,
                $line,
            ));
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_name($this->cookie['name'] ?? 'auth_engine_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookie['path'] ?? '/',
            'domain' => $this->cookie['domain'] ?? '',
            'secure' => $this->cookie['secure'] ?? self::isHttps(),
            'httponly' => $this->cookie['http_only'] ?? true,
            'samesite' => $this->cookie['same_site'] ?? 'Lax',
        ]);

        if (!session_start()) {
            throw new AuthEngineException('Unable to start the PHP session.');
        }
    }

    public function getId(): string
    {
        return (string) session_id();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION ?? []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $_SESSION[$key] : $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(bool $destroyOld = true): void
    {
        $this->start();
        session_regenerate_id($destroyOld);
    }

    public function invalidate(): void
    {
        $this->start();
        $_SESSION = [];
        session_regenerate_id(true);
    }

    private static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

        return ($https !== '' && $https !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
