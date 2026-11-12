<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Unit;

use BadMethodCallException;
use PHPUnit\Framework\TestCase;
use ReyhanItsMe\AuthEngine\Adapters\Native\NativeAdapter;
use ReyhanItsMe\AuthEngine\Auth;
use ReyhanItsMe\AuthEngine\Console\Application;
use ReyhanItsMe\AuthEngine\Core\Version;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;
use ReyhanItsMe\AuthEngine\Exceptions\NotBootedException;
use ReyhanItsMe\AuthEngine\Tests\Fixtures\FakeUser;

final class AuthFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Auth::reset();
    }

    public function testUsingFacadeBeforeBootThrows(): void
    {
        self::assertFalse(Auth::isBooted());

        $this->expectException(NotBootedException::class);
        Auth::check();
    }

    public function testBootAutoSelectsNativeAdapterAndForwardsCalls(): void
    {
        $manager = Auth::boot(['identifiers' => ['email', 'username']]);

        self::assertTrue(Auth::isBooted());
        self::assertSame($manager, Auth::manager());
        self::assertInstanceOf(NativeAdapter::class, Auth::adapter());
        self::assertSame(['email', 'username'], Auth::config()->get('identifiers'));

        Auth::login(new FakeUser(5));
        self::assertTrue(Auth::check());
        self::assertSame(5, Auth::id());

        Auth::logout();
        self::assertTrue(Auth::guest());
    }

    public function testRequestingLaravelAdapterOutsideLaravelThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        Auth::boot(['adapter' => 'laravel']);
    }

    public function testUnknownMethodThrows(): void
    {
        Auth::boot();

        $this->expectException(BadMethodCallException::class);
        Auth::flyToTheMoon();
    }

    public function testVersionMatchesComposerFriendlySemver(): void
    {
        self::assertSame(Version::VERSION, Auth::version());
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Auth::version());
    }

    public function testConsoleCommands(): void
    {
        $run = static function (string ...$args): array {
            $stream = fopen('php://memory', 'w+');
            $code = (new Application($stream, false))->run(['easy-auth', ...$args]);
            rewind($stream);

            return [$code, (string) stream_get_contents($stream)];
        };

        [$code, $out] = $run('--version');
        self::assertSame(0, $code);
        self::assertSame('easy-auth ' . Version::VERSION . PHP_EOL, $out);

        [$code, $out] = $run();
        self::assertSame(0, $code);
        self::assertStringContainsString('install:ui', $out);
        self::assertStringNotContainsString("\033[", $out);

        [$code, $out] = $run('about');
        self::assertSame(0, $code);
        self::assertStringContainsString('credentials', $out);

        [$code, $out] = $run('nope');
        self::assertSame(1, $code);
        self::assertStringContainsString('"nope" is not defined', $out);
    }
}
