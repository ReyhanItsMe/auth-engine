<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Adapters\Native;

use ReyhanItsMe\AuthEngine\Contracts\FrameworkAdapterInterface;
use ReyhanItsMe\AuthEngine\Contracts\SessionInterface;
use ReyhanItsMe\AuthEngine\Core\Config;

/**
 * Adapter for plain PHP applications (no framework).
 *
 * Under the CLI SAPI an in-memory ArraySession is used automatically,
 * because cookies/headers are not available there.
 */
final class NativeAdapter implements FrameworkAdapterInterface
{
    private ?SessionInterface $session;

    public function __construct(private readonly Config $config, ?SessionInterface $session = null)
    {
        $this->session = $session;
    }

    public function name(): string
    {
        return 'native';
    }

    public function session(): SessionInterface
    {
        return $this->session ??= PHP_SAPI === 'cli'
            ? new ArraySession()
            : new NativeSession((array) $this->config->get('session.cookie', []));
    }

    /**
     * Uses REMOTE_ADDR only. Proxy headers (X-Forwarded-For) are deliberately
     * not trusted because they can be spoofed by the client.
     */
    public function ipAddress(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public function userAgent(): ?string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return is_string($agent) && $agent !== '' ? mb_substr($agent, 0, 512) : null;
    }

    public function baseUrl(): string
    {
        $configured = $this->config->get('app.url');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $host = is_string($host) && preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host) === 1 ? $host : 'localhost';

        return $scheme . '://' . $host;
    }
}
