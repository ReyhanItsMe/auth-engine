<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReyhanItsMe\AuthEngine\Adapters\Laravel\LaravelAdapter;
use ReyhanItsMe\AuthEngine\Adapters\Native\ArraySession;
use ReyhanItsMe\AuthEngine\Adapters\Native\NativeAdapter;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\ConfigLoader;
use ReyhanItsMe\AuthEngine\Tests\Fixtures\FakeUser;

final class AdaptersTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testNativeAdapterReadsRequestContext(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'app.test:8443';

        $adapter = new NativeAdapter(ConfigLoader::load(['app' => ['url' => null]]));

        self::assertSame('native', $adapter->name());
        self::assertSame('203.0.113.9', $adapter->ipAddress(), 'proxy headers are not trusted');
        self::assertSame('PHPUnit', $adapter->userAgent());
        self::assertSame('https://app.test:8443', $adapter->baseUrl());
        self::assertInstanceOf(ArraySession::class, $adapter->session(), 'CLI falls back to ArraySession');
        self::assertSame($adapter->session(), $adapter->session());
    }

    public function testNativeAdapterPrefersConfiguredUrlAndRejectsBadInput(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        $_SERVER['HTTP_HOST'] = 'evil.test/<script>';
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTPS']);

        $config = ConfigLoader::load(['app' => ['url' => null]]);
        $adapter = new NativeAdapter($config);

        self::assertNull($adapter->ipAddress());
        self::assertNull($adapter->userAgent());
        self::assertSame('http://localhost', $adapter->baseUrl());

        $configured = new NativeAdapter($config->with('app.url', 'https://example.com/'));
        self::assertSame('https://example.com', $configured->baseUrl());
    }

    public function testLaravelAdapterBridgesContainerSessionAndRequest(): void
    {
        $store = new class {
            /** @var array<string, mixed> */
            public array $data = [];
            public bool $started = false;
            public int $migrations = 0;
            public string $id = 'sess-1';

            public function isStarted(): bool
            {
                return $this->started;
            }

            public function start(): bool
            {
                return $this->started = true;
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function exists(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function put(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function forget(string $key): void
            {
                unset($this->data[$key]);
            }

            public function migrate(bool $destroy = false): bool
            {
                $this->migrations++;
                $this->id = 'sess-' . ($this->migrations + 1);

                return true;
            }

            public function invalidate(): bool
            {
                $this->data = [];

                return $this->migrate(true);
            }
        };

        $request = new class {
            public function ip(): string
            {
                return '198.51.100.4';
            }

            public function userAgent(): string
            {
                return 'Laravel-UA';
            }

            public function root(): string
            {
                return 'https://laravel.test/';
            }
        };

        $app = new class ($store, $request) {
            public function __construct(private object $store, private object $request)
            {
            }

            public function make(string $abstract): object
            {
                return $abstract === 'session.store' ? $this->store : $this->request;
            }
        };

        $config = ConfigLoader::load(['app' => ['url' => null]]);
        $adapter = new LaravelAdapter($app, $config);

        self::assertSame('laravel', $adapter->name());
        self::assertSame('198.51.100.4', $adapter->ipAddress());
        self::assertSame('Laravel-UA', $adapter->userAgent());
        self::assertSame('https://laravel.test', $adapter->baseUrl());

        $auth = new AuthManager($config, $adapter);
        $auth->login(new FakeUser(77));

        self::assertTrue($store->started);
        self::assertSame(1, $store->migrations, 'login migrates the Laravel session');
        self::assertSame(77, $store->data['auth_engine.user_id']);
        self::assertSame(77, $auth->id());

        $auth->logout();
        self::assertArrayNotHasKey('auth_engine.user_id', $store->data);
        self::assertSame(2, $store->migrations);

        $adapter->session()->invalidate();
        self::assertSame([], $store->data);
    }
}
