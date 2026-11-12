<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine;

use BadMethodCallException;
use ReyhanItsMe\AuthEngine\Adapters\AdapterResolver;
use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Contracts\AuthMethodInterface;
use ReyhanItsMe\AuthEngine\Contracts\FrameworkAdapterInterface;
use ReyhanItsMe\AuthEngine\Contracts\UserProviderInterface;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\AuthResult;
use ReyhanItsMe\AuthEngine\Core\Config;
use ReyhanItsMe\AuthEngine\Core\ConfigLoader;
use ReyhanItsMe\AuthEngine\Core\Version;
use ReyhanItsMe\AuthEngine\Exceptions\NotBootedException;

/**
 * Static entry point of the package.
 *
 *   Auth::boot(['identifiers' => ['email', 'username']]);
 *   if (Auth::check()) { $user = Auth::user(); }
 *
 * Every other call is forwarded to the underlying AuthManager, so features
 * added by later drivers are reachable through the same facade.
 *
 * @method static Config config()
 * @method static FrameworkAdapterInterface adapter()
 * @method static AuthManager setPdo(\PDO $pdo)
 * @method static void installSchema()
 * @method static AuthManager setUserProvider(UserProviderInterface $users)
 * @method static UserProviderInterface users()
 * @method static AuthManager setNotifier(\ReyhanItsMe\AuthEngine\Contracts\NotifierInterface|callable $notifier)
 * @method static AuthManager setHttpClient(\ReyhanItsMe\AuthEngine\Contracts\HttpClientInterface $http)
 * @method static AuthMethodInterface driver(string $name)
 * @method static AuthManager extend(string $name, callable $factory)
 * @method static list<string> enabledDrivers()
 * @method static AuthenticatableInterface register(array $data)
 * @method static AuthResult attempt(string $identifier, string $password)
 * @method static void sendMagicLink(string $email)
 * @method static AuthResult loginWithMagicLink(string $token)
 * @method static void sendOtp(string $recipient, string $channel = 'email')
 * @method static bool verifyOtp(string $recipient, string $code, string $channel = 'email')
 * @method static AuthResult loginWithOtp(string $recipient, string $code, string $channel = 'email')
 * @method static array|null pendingMfa()
 * @method static bool sendMfaOtp()
 * @method static AuthResult verifyMfa(string $code, ?string $method = null)
 * @method static list<array{key: string, name: string, color: string, icon: string, url: string}> getActiveProviders()
 * @method static \ReyhanItsMe\AuthEngine\Methods\Credentials\CredentialsDriver credentials()
 * @method static \ReyhanItsMe\AuthEngine\Methods\MagicLink\MagicLinkDriver magicLink()
 * @method static \ReyhanItsMe\AuthEngine\Methods\Otp\OtpDriver otp()
 * @method static \ReyhanItsMe\AuthEngine\Methods\Totp\TotpDriver totp()
 * @method static \ReyhanItsMe\AuthEngine\Methods\WebAuthn\WebAuthnDriver webauthn()
 * @method static \ReyhanItsMe\AuthEngine\OAuth\OAuthManager oauth()
 * @method static AuthResult authenticate(AuthenticatableInterface $user, string $via, bool $mfaSatisfied = false)
 * @method static void login(AuthenticatableInterface $user)
 * @method static void logout()
 * @method static bool check()
 * @method static bool guest()
 * @method static int|string|null id()
 * @method static AuthenticatableInterface|null user()
 * @method static int|null authenticatedAt()
 */
final class Auth
{
    private static ?AuthManager $manager = null;

    private function __construct()
    {
    }

    /**
     * Boot the engine once during application bootstrap.
     *
     * @param array<string, mixed>|string|Config|null $config overrides array, path to a config file,
     *                                                        a ready Config, or null for defaults
     */
    public static function boot(
        array|string|Config|null $config = null,
        ?FrameworkAdapterInterface $adapter = null,
    ): AuthManager {
        $config = $config instanceof Config ? $config : ConfigLoader::load($config);
        $adapter ??= AdapterResolver::resolve($config);

        return self::$manager = new AuthManager($config, $adapter);
    }

    public static function isBooted(): bool
    {
        return self::$manager !== null;
    }

    public static function manager(): AuthManager
    {
        if (self::$manager !== null) {
            return self::$manager;
        }

        // Inside Laravel the service provider builds the manager on demand.
        if (function_exists('app')) {
            $app = \app();
            if (is_object($app) && method_exists($app, 'bound') && $app->bound(AuthManager::class)) {
                return self::$manager = $app->make(AuthManager::class);
            }
        }

        throw NotBootedException::create();
    }

    public static function setManager(?AuthManager $manager): void
    {
        self::$manager = $manager;
    }

    /** Forget the booted instance (mainly for tests and long-running workers). */
    public static function reset(): void
    {
        self::$manager = null;
    }

    public static function version(): string
    {
        return Version::VERSION;
    }

    /** @param array<int, mixed> $arguments */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $manager = self::manager();

        if (!method_exists($manager, $method)) {
            throw new BadMethodCallException(sprintf('Method %s::%s() does not exist.', self::class, $method));
        }

        return $manager->{$method}(...$arguments);
    }
}
