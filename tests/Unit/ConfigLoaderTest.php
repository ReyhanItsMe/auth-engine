<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReyhanItsMe\AuthEngine\Core\ConfigLoader;
use ReyhanItsMe\AuthEngine\Core\Env;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;

final class ConfigLoaderTest extends TestCase
{
    public function testDefaultsAreValidAndOnlyCredentialsIsEnabled(): void
    {
        $config = ConfigLoader::load();

        self::assertSame(['email'], $config->get('identifiers'));
        self::assertSame('argon2id', $config->get('hashing.algo'));
        self::assertSame(['credentials'], $config->enabledDrivers());
        self::assertFalse($config->isDriverEnabled('totp'));
        self::assertFalse($config->isDriverEnabled('does-not-exist'));
    }

    public function testOverridesAreDeepMergedAndListsAreReplaced(): void
    {
        $config = ConfigLoader::load([
            'identifiers' => ['username', 'phone_number'],
            'session' => ['lifetime' => 30],
            'app' => ['key' => str_repeat('k', 32)],
            'drivers' => ['totp' => ['enabled' => true, 'issuer' => 'Acme']],
        ]);

        self::assertSame(['username', 'phone_number'], $config->get('identifiers'));
        self::assertSame(30, $config->get('session.lifetime'));
        self::assertSame('auth_engine', $config->get('session.key'), 'untouched siblings keep defaults');
        self::assertSame('Acme', $config->get('drivers.totp.issuer'));
        self::assertSame(30, $config->get('drivers.totp.period'));
        self::assertSame(['credentials', 'totp'], $config->enabledDrivers());
    }

    public function testLoadsFromFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ae') . '.php';
        file_put_contents($file, "<?php return ['hashing' => ['algo' => 'bcrypt']];");

        try {
            self::assertSame('bcrypt', ConfigLoader::load($file)->get('hashing.algo'));
        } finally {
            unlink($file);
        }
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        ConfigLoader::load('/nope/auth-engine.php');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'unknown identifier' => [['identifiers' => ['nickname']], 'Invalid identifier'];
        yield 'empty identifiers' => [['identifiers' => []], 'non-empty'];
        yield 'bad algo' => [['hashing' => ['algo' => 'md5']], 'hashing.algo'];
        yield 'bad adapter' => [['adapter' => 'symfony'], 'adapter'];
        yield 'negative lifetime' => [['session' => ['lifetime' => -1]], 'session.lifetime'];
        yield 'driver without flag' => [['drivers' => ['custom' => ['ttl' => 1]]], 'boolean "enabled"'];
        yield 'magic link without key' => [
            ['app' => ['key' => null], 'drivers' => ['magic_link' => ['enabled' => true]]],
            'app.key',
        ];
        yield 'short key' => [
            ['app' => ['key' => 'short'], 'drivers' => ['totp' => ['enabled' => true]]],
            'app.key',
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigIsRejected(array $overrides, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        ConfigLoader::load($overrides);
    }

    public function testKeyDependentDriverAcceptsStrongKey(): void
    {
        $config = ConfigLoader::load([
            'app' => ['key' => str_repeat('k', 32)],
            'drivers' => ['magic_link' => ['enabled' => true]],
        ]);

        self::assertTrue($config->isDriverEnabled('magic_link'));
    }

    public function testConfigIsImmutableAndSupportsDotAccess(): void
    {
        $config = ConfigLoader::load();
        $changed = $config->with('session.lifetime', 5)->with('custom.deep.value', 'x');

        self::assertSame(120, $config->get('session.lifetime'));
        self::assertSame(5, $changed->get('session.lifetime'));
        self::assertSame('x', $changed->get('custom.deep.value'));
        self::assertTrue($config->has('app.key'), 'null values still count as present');
        self::assertFalse($config->has('app.missing'));
        self::assertSame('fallback', $config->get('a.b.c', 'fallback'));
    }

    public function testEnvCastsCommonLiterals(): void
    {
        $_ENV['AE_TEST_TRUE'] = 'true';
        $_ENV['AE_TEST_NULL'] = '(null)';
        $_ENV['AE_TEST_TEXT'] = 'hello';

        try {
            self::assertTrue(Env::get('AE_TEST_TRUE'));
            self::assertNull(Env::get('AE_TEST_NULL', 'default'));
            self::assertSame('hello', Env::get('AE_TEST_TEXT'));
            self::assertSame('default', Env::get('AE_TEST_MISSING', 'default'));
        } finally {
            unset($_ENV['AE_TEST_TRUE'], $_ENV['AE_TEST_NULL'], $_ENV['AE_TEST_TEXT']);
        }
    }
}
