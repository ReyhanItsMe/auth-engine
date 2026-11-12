<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Adapters\Laravel;

use ReyhanItsMe\AuthEngine\Contracts\FrameworkAdapterInterface;
use ReyhanItsMe\AuthEngine\Contracts\SessionInterface;
use PDO;
use ReyhanItsMe\AuthEngine\Contracts\NotifierInterface;
use ReyhanItsMe\AuthEngine\Core\Config;
use ReyhanItsMe\AuthEngine\Notifications\LaravelNotifier;

/**
 * Adapter for Laravel. Session and request are resolved lazily from the
 * container on every call, so it stays correct across requests (Octane, tests).
 */
final class LaravelAdapter implements FrameworkAdapterInterface
{
    /** @param object $app the Laravel application / container (must expose make()) */
    public function __construct(private readonly object $app, private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'laravel';
    }

    public function session(): SessionInterface
    {
        return new LaravelSession($this->app->make('session.store'));
    }

    public function ipAddress(): ?string
    {
        $ip = $this->app->make('request')->ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    public function userAgent(): ?string
    {
        $agent = $this->app->make('request')->userAgent();

        return is_string($agent) && $agent !== '' ? mb_substr($agent, 0, 512) : null;
    }

    public function baseUrl(): string
    {
        $configured = $this->config->get('app.url');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) $this->app->make('request')->root(), '/');
    }

    /** PDO of the application's default database connection. */
    public function pdo(): PDO
    {
        return $this->app->make('db')->connection()->getPdo();
    }

    /** Default notifier: the application's mailer. */
    public function notifier(): NotifierInterface
    {
        return new LaravelNotifier($this->app);
    }
}
