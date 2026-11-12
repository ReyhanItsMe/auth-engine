<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReyhanItsMe\AuthEngine\Adapters\Native\ArraySession;
use ReyhanItsMe\AuthEngine\Adapters\Native\NativeAdapter;
use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Contracts\AuthMethodInterface;
use ReyhanItsMe\AuthEngine\Contracts\UserProviderInterface;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\ConfigLoader;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;
use ReyhanItsMe\AuthEngine\Exceptions\DriverNotEnabledException;
use ReyhanItsMe\AuthEngine\Tests\Fixtures\FakeUser;

final class AuthManagerTest extends TestCase
{
    private ArraySession $session;

    private int $now = 1_000_000;

    /** @param array<string, mixed> $overrides */
    private function manager(array $overrides = []): AuthManager
    {
        $config = ConfigLoader::load($overrides);
        $this->session ??= new ArraySession();

        return new AuthManager($config, new NativeAdapter($config, $this->session), null, fn (): int => $this->now);
    }

    /** @param array<int|string, FakeUser> $users */
    private function provider(array $users): UserProviderInterface
    {
        return new class ($users) implements UserProviderInterface {
            public int $lookups = 0;

            /** @param array<int|string, FakeUser> $users */
            public function __construct(private array $users)
            {
            }

            public function findById(int|string $id): ?AuthenticatableInterface
            {
                $this->lookups++;

                return $this->users[$id] ?? null;
            }

            public function findByIdentifier(string $field, string $value): ?AuthenticatableInterface
            {
                return null;
            }
        };
    }

    public function testGuestByDefault(): void
    {
        $auth = $this->manager();

        self::assertTrue($auth->guest());
        self::assertFalse($auth->check());
        self::assertNull($auth->id());
        self::assertNull($auth->user());
        self::assertNull($auth->authenticatedAt());
    }

    public function testLoginStoresUserAndRegeneratesSessionId(): void
    {
        $auth = $this->manager();
        $before = $this->session->getId();

        $user = new FakeUser(42);
        $auth->login($user);

        self::assertNotSame($before, $this->session->getId(), 'session fixation prevention');
        self::assertTrue($auth->check());
        self::assertSame(42, $auth->id());
        self::assertSame($user, $auth->user());
        self::assertSame($this->now, $auth->authenticatedAt());
    }

    public function testLogoutClearsStateAndRegeneratesSessionId(): void
    {
        $auth = $this->manager();
        $auth->login(new FakeUser(1));
        $this->session->put('cart', ['item']);
        $before = $this->session->getId();

        $auth->logout();

        self::assertTrue($auth->guest());
        self::assertNotSame($before, $this->session->getId());
        self::assertSame(['cart' => ['item']], $this->session->all(), 'only package keys are removed');
    }

    public function testUserIsRestoredFromSessionThroughProviderOnNextRequest(): void
    {
        $this->manager()->login(new FakeUser('u-7'));

        // A new manager on the same session simulates the next HTTP request.
        $provider = $this->provider(['u-7' => new FakeUser('u-7')]);
        $next = $this->manager()->setUserProvider($provider);

        self::assertSame('u-7', $next->user()?->getAuthIdentifier());
        $next->user();
        self::assertSame(1, $provider->lookups, 'user is cached per request');
    }

    public function testResolvingUserWithoutProviderThrows(): void
    {
        $this->manager()->login(new FakeUser(1));

        $this->expectException(ConfigurationException::class);
        $this->manager()->user();
    }

    public function testDeletedUserIsLoggedOut(): void
    {
        $this->manager()->login(new FakeUser(9));
        $next = $this->manager()->setUserProvider($this->provider([]));

        self::assertNull($next->user());
        self::assertTrue($next->guest());
    }

    public function testIdleTimeoutExpiresSession(): void
    {
        $auth = $this->manager(['session' => ['lifetime' => 10]]);
        $auth->login(new FakeUser(1));

        $this->now += 9 * 60;
        self::assertTrue($auth->check(), 'activity inside the window keeps the session alive');

        $this->now += 9 * 60;
        self::assertTrue($auth->check(), 'the window slides with activity');

        $this->now += 10 * 60 + 1;
        self::assertFalse($auth->check());
        self::assertNull($this->session->get('auth_engine.user_id'));
    }

    public function testZeroLifetimeNeverExpires(): void
    {
        $auth = $this->manager(['session' => ['lifetime' => 0]]);
        $auth->login(new FakeUser(1));
        $this->now += 365 * 86400;

        self::assertTrue($auth->check());
    }

    public function testDisabledDriverIsNeverInstantiated(): void
    {
        $built = 0;
        $auth = $this->manager();
        $auth->extend('totp', function () use (&$built): AuthMethodInterface {
            $built++;

            return self::driver('totp');
        });

        try {
            $auth->driver('totp');
            self::fail('Expected DriverNotEnabledException.');
        } catch (DriverNotEnabledException $e) {
            self::assertStringContainsString('"totp" is disabled', $e->getMessage());
        }

        self::assertSame(0, $built);
        self::assertFalse($auth->drivers()->isResolved('totp'));
    }

    public function testEnabledDriverIsBuiltLazilyAndOnlyOnce(): void
    {
        $built = 0;
        $auth = $this->manager(['app' => ['key' => str_repeat('k', 32)], 'drivers' => ['totp' => ['enabled' => true]]]);
        $auth->extend('totp', function (AuthManager $manager) use (&$built, $auth): AuthMethodInterface {
            $built++;
            self::assertSame($auth, $manager);

            return self::driver('totp');
        });

        self::assertSame(0, $built, 'registering does not instantiate');
        self::assertSame($auth->driver('totp'), $auth->driver('totp'));
        self::assertSame(1, $built);
        self::assertSame(['credentials', 'totp'], $auth->enabledDrivers());
    }

    public function testEnabledButUnregisteredDriverThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No auth driver registered under "sso"');

        $this->manager(['drivers' => ['sso' => ['enabled' => true]]])->driver('sso');
    }

    private static function driver(string $name): AuthMethodInterface
    {
        return new class ($name) implements AuthMethodInterface {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }
        };
    }
}
