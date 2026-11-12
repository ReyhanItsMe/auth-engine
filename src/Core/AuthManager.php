<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

use Closure;
use PDO;
use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Contracts\AuthMethodInterface;
use ReyhanItsMe\AuthEngine\Contracts\FrameworkAdapterInterface;
use ReyhanItsMe\AuthEngine\Contracts\HttpClientInterface;
use ReyhanItsMe\AuthEngine\Contracts\NotifiableInterface;
use ReyhanItsMe\AuthEngine\Contracts\NotifierInterface;
use ReyhanItsMe\AuthEngine\Contracts\SessionInterface;
use ReyhanItsMe\AuthEngine\Contracts\UserProviderInterface;
use ReyhanItsMe\AuthEngine\Database\Connection;
use ReyhanItsMe\AuthEngine\Database\PdoUserProvider;
use ReyhanItsMe\AuthEngine\Database\Schema;
use ReyhanItsMe\AuthEngine\Exceptions\DriverNotEnabledException;
use ReyhanItsMe\AuthEngine\Http\CurlHttpClient;
use ReyhanItsMe\AuthEngine\Methods\Credentials\CredentialsDriver;
use ReyhanItsMe\AuthEngine\Methods\MagicLink\MagicLinkDriver;
use ReyhanItsMe\AuthEngine\Methods\Otp\OtpDriver;
use ReyhanItsMe\AuthEngine\Methods\Totp\TotpDriver;
use ReyhanItsMe\AuthEngine\Methods\WebAuthn\WebAuthnDriver;
use ReyhanItsMe\AuthEngine\Notifications\ArrayNotifier;
use ReyhanItsMe\AuthEngine\Notifications\CallbackNotifier;
use ReyhanItsMe\AuthEngine\Notifications\LogNotifier;
use ReyhanItsMe\AuthEngine\Notifications\MailNotifier;
use ReyhanItsMe\AuthEngine\OAuth\OAuthManager;

/**
 * The heart of the package: owns the authenticated-user state, the shared
 * services, and hands out the (lazily built) authentication drivers.
 */
final class AuthManager
{
    private const SESSION_ENTRIES = [
        'user_id', 'authenticated_at', 'last_activity',
        'mfa_pending', 'webauthn_register', 'webauthn_login', 'oauth_state',
    ];

    private ?AuthenticatableInterface $user = null;
    private ?UserProviderInterface $users = null;
    private ?PDO $pdo = null;
    private ?Connection $db = null;
    private ?Crypto $crypto = null;
    private ?Hasher $hasher = null;
    private ?RateLimiter $limiter = null;
    private ?TokenStore $tokens = null;
    private ?NotifierInterface $notifier = null;
    private ?HttpClientInterface $http = null;

    private readonly DriverRegistry $drivers;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param (callable(): int)|null $clock unix-time source, injectable for tests */
    public function __construct(
        private readonly Config $config,
        private readonly FrameworkAdapterInterface $adapter,
        ?DriverRegistry $drivers = null,
        ?callable $clock = null,
    ) {
        $this->drivers = $drivers ?? new DriverRegistry();
        $this->clock = $clock !== null ? $clock(...) : time(...);

        // Built-in drivers are registered as factories only: nothing is built until used.
        $builtIn = [
            'credentials' => static fn (self $auth): AuthMethodInterface => new CredentialsDriver($auth),
            'magic_link' => static fn (self $auth): AuthMethodInterface => new MagicLinkDriver($auth),
            'otp' => static fn (self $auth): AuthMethodInterface => new OtpDriver($auth),
            'totp' => static fn (self $auth): AuthMethodInterface => new TotpDriver($auth),
            'webauthn' => static fn (self $auth): AuthMethodInterface => new WebAuthnDriver($auth),
            'oauth' => static fn (self $auth): AuthMethodInterface => new OAuthManager($auth),
        ];
        foreach ($builtIn as $name => $factory) {
            if (!$this->drivers->has($name)) {
                $this->drivers->register($name, $factory);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Environment & shared services
    // ---------------------------------------------------------------------

    public function config(): Config
    {
        return $this->config;
    }

    public function adapter(): FrameworkAdapterInterface
    {
        return $this->adapter;
    }

    public function session(): SessionInterface
    {
        $session = $this->adapter->session();
        $session->start();

        return $session;
    }

    /** Full session key for one of the package's entries. */
    public function sessionKey(string $entry): string
    {
        return $this->config->get('session.key', 'auth_engine') . '.' . $entry;
    }

    public function version(): string
    {
        return Version::VERSION;
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /** Use an existing PDO connection instead of "database.dsn". */
    public function setPdo(PDO $pdo): static
    {
        $this->pdo = $pdo;
        $this->db = $this->limiter = $this->tokens = null;

        return $this;
    }

    public function db(): Connection
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $pdo = $this->pdo ?? (method_exists($this->adapter, 'pdo') ? $this->adapter->pdo() : null);

        return $this->db = $pdo instanceof PDO
            ? new Connection($pdo, $this->config)
            : Connection::fromConfig($this->config);
    }

    /** Create the package tables (safe to run repeatedly). */
    public function installSchema(): void
    {
        Schema::install($this->db());
    }

    public function crypto(): Crypto
    {
        return $this->crypto ??= new Crypto($this->config->get('app.key'));
    }

    public function hasher(): Hasher
    {
        return $this->hasher ??= new Hasher($this->config);
    }

    public function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter($this->db(), $this->clock);
    }

    public function tokens(): TokenStore
    {
        return $this->tokens ??= new TokenStore($this->db(), $this->crypto(), $this->clock);
    }

    /** @param NotifierInterface|callable(string, string, string, string, array<string, mixed>): void $notifier */
    public function setNotifier(NotifierInterface|callable $notifier): static
    {
        $this->notifier = $notifier instanceof NotifierInterface ? $notifier : new CallbackNotifier($notifier);

        return $this;
    }

    public function notifier(): NotifierInterface
    {
        if ($this->notifier !== null) {
            return $this->notifier;
        }

        $from = $this->config->get('notifications.from');
        $from = is_string($from) ? $from : null;

        return $this->notifier = match ($this->config->get('notifications.driver', 'auto')) {
            'log' => new LogNotifier(),
            'array' => new ArrayNotifier(),
            'mail' => new MailNotifier($from),
            default => method_exists($this->adapter, 'notifier') ? $this->adapter->notifier() : new MailNotifier($from),
        };
    }

    public function setHttpClient(HttpClientInterface $http): static
    {
        $this->http = $http;

        return $this;
    }

    public function http(): HttpClientInterface
    {
        return $this->http ??= new CurlHttpClient();
    }

    // ---------------------------------------------------------------------
    // Rate limiting helpers (no-ops when "rate_limit.enabled" is false)
    // ---------------------------------------------------------------------

    /** @return int|null seconds to wait when $key is currently blocked, null otherwise */
    public function throttled(string $key, ?int $maxAttempts = null): ?int
    {
        if ($this->config->get('rate_limit.enabled') !== true) {
            return null;
        }

        $max = $maxAttempts ?? (int) $this->config->get('rate_limit.max_attempts', 5);

        return $this->limiter()->tooManyAttempts($key, $max) ? max(1, $this->limiter()->availableIn($key)) : null;
    }

    public function hitThrottle(string $key, ?int $decaySeconds = null): void
    {
        if ($this->config->get('rate_limit.enabled') === true) {
            $this->limiter()->hit($key, $decaySeconds ?? (int) $this->config->get('rate_limit.decay_seconds', 60));
        }
    }

    public function clearThrottle(string $key): void
    {
        if ($this->config->get('rate_limit.enabled') === true) {
            $this->limiter()->clear($key);
        }
    }

    // ---------------------------------------------------------------------
    // Drivers
    // ---------------------------------------------------------------------

    /**
     * Register (or replace) a driver factory. Nothing is instantiated here.
     *
     * @param callable(AuthManager): AuthMethodInterface $factory
     */
    public function extend(string $name, callable $factory): static
    {
        $this->drivers->register($name, $factory);

        return $this;
    }

    public function driver(string $name): AuthMethodInterface
    {
        if (!$this->config->isDriverEnabled($name)) {
            throw DriverNotEnabledException::forDriver($name);
        }

        return $this->drivers->resolve($name, $this);
    }

    /** @return list<string> */
    public function enabledDrivers(): array
    {
        return $this->config->enabledDrivers();
    }

    public function drivers(): DriverRegistry
    {
        return $this->drivers;
    }

    public function credentials(): CredentialsDriver
    {
        return $this->typed('credentials', CredentialsDriver::class);
    }

    public function magicLink(): MagicLinkDriver
    {
        return $this->typed('magic_link', MagicLinkDriver::class);
    }

    public function otp(): OtpDriver
    {
        return $this->typed('otp', OtpDriver::class);
    }

    public function totp(): TotpDriver
    {
        return $this->typed('totp', TotpDriver::class);
    }

    public function webauthn(): WebAuthnDriver
    {
        return $this->typed('webauthn', WebAuthnDriver::class);
    }

    public function oauth(): OAuthManager
    {
        return $this->typed('oauth', OAuthManager::class);
    }

    // ---------------------------------------------------------------------
    // Shortcuts (each one needs its driver to be enabled)
    // ---------------------------------------------------------------------

    /**
     * Create an account. Logs the user in when "registration.auto_login" is true.
     *
     * @param array<string, mixed> $data email / username / phone_number, password, name
     */
    public function register(array $data): AuthenticatableInterface
    {
        $user = $this->credentials()->register($data);

        if ($this->config->get('registration.auto_login') === true) {
            $this->login($user);
        }

        return $user;
    }

    /** Log in with an identifier (email, username or phone number) and a password. */
    public function attempt(string $identifier, string $password): AuthResult
    {
        return $this->credentials()->attempt($identifier, $password);
    }

    public function sendMagicLink(string $email): void
    {
        $this->magicLink()->send($email);
    }

    public function loginWithMagicLink(string $token): AuthResult
    {
        return $this->magicLink()->login($token);
    }

    public function sendOtp(string $recipient, string $channel = 'email'): void
    {
        $this->otp()->send($recipient, $channel);
    }

    public function verifyOtp(string $recipient, string $code, string $channel = 'email'): bool
    {
        return $this->otp()->verify($recipient, $code, $channel);
    }

    public function loginWithOtp(string $recipient, string $code, string $channel = 'email'): AuthResult
    {
        return $this->otp()->login($recipient, $code, $channel);
    }

    /**
     * Configured OAuth providers with display metadata and raw SimpleIcons SVG.
     *
     * @return list<array{key: string, name: string, color: string, icon: string, url: string}>
     */
    public function getActiveProviders(): array
    {
        return $this->config->isDriverEnabled('oauth') ? $this->oauth()->activeProviders() : [];
    }

    // ---------------------------------------------------------------------
    // User state
    // ---------------------------------------------------------------------

    public function setUserProvider(UserProviderInterface $users): static
    {
        $this->users = $users;

        return $this;
    }

    /** The configured user provider; defaults to the built-in PdoUserProvider. */
    public function users(): UserProviderInterface
    {
        return $this->users ??= new PdoUserProvider($this->db());
    }

    /**
     * Finish a successful first factor: logs the user in, or parks the login
     * until a second factor is verified (see verifyMfa()).
     *
     * @param string $via          the driver that verified the user (credentials, magic_link, otp, oauth, webauthn)
     * @param bool   $mfaSatisfied true when the method itself is multi-factor (e.g. a user-verified passkey)
     */
    public function authenticate(AuthenticatableInterface $user, string $via, bool $mfaSatisfied = false): AuthResult
    {
        $methods = $mfaSatisfied ? [] : $this->mfaMethodsFor($user, $via);

        if ($methods === []) {
            $this->login($user);

            return AuthResult::success($user);
        }

        $session = $this->session();
        $session->regenerate(true);
        $session->put($this->sessionKey('mfa_pending'), [
            'user_id' => $user->getAuthIdentifier(),
            'methods' => $methods,
            'via' => $via,
            'expires_at' => $this->now() + (int) $this->config->get('mfa.challenge_ttl', 600),
        ]);

        return AuthResult::mfaRequired($methods);
    }

    /**
     * Second factors available to $user: totp + recovery, and/or otp.
     *
     * @return list<string>
     */
    public function mfaMethodsFor(AuthenticatableInterface $user, string $via = ''): array
    {
        $methods = [];

        if ($this->config->isDriverEnabled('totp') && $this->totp()->isEnabled($user)) {
            $methods = ['totp', 'recovery'];
        }

        if (
            $via !== 'otp'
            && $this->config->isDriverEnabled('otp')
            && $this->config->get('drivers.otp.second_factor') === true
            && $user instanceof NotifiableInterface
            && $user->routeNotificationFor('email') !== null
        ) {
            $methods[] = 'otp';
        }

        return $methods;
    }

    /**
     * The login waiting for a second factor, if any.
     *
     * @return array{user_id: int|string, methods: list<string>, via: string, expires_at: int}|null
     */
    public function pendingMfa(): ?array
    {
        $session = $this->session();
        $pending = $session->get($this->sessionKey('mfa_pending'));

        if (!is_array($pending) || !isset($pending['user_id'], $pending['methods'], $pending['expires_at'])) {
            return null;
        }

        if ((int) $pending['expires_at'] <= $this->now()) {
            $session->forget($this->sessionKey('mfa_pending'));

            return null;
        }

        return $pending;
    }

    /** E-mail a one-time code for the pending second step (needs the "otp" method). */
    public function sendMfaOtp(): bool
    {
        $pending = $this->pendingMfa();
        if ($pending === null || !in_array('otp', $pending['methods'], true)) {
            return false;
        }

        $user = $this->users()->findById($pending['user_id']);
        $email = $user instanceof NotifiableInterface ? $user->routeNotificationFor('email') : null;
        if ($email === null) {
            return false;
        }

        $this->otp()->send($email, 'email');

        return true;
    }

    /**
     * Complete a pending login with a second factor.
     *
     * @param string|null $method totp | recovery | otp; null tries every method the user has
     */
    public function verifyMfa(string $code, ?string $method = null): AuthResult
    {
        $pending = $this->pendingMfa();
        if ($pending === null) {
            return AuthResult::failed('no_pending_challenge');
        }

        $user = $this->users()->findById($pending['user_id']);
        if ($user === null) {
            $this->session()->forget($this->sessionKey('mfa_pending'));

            return AuthResult::failed('no_pending_challenge');
        }

        if ($method !== null && !in_array($method, $pending['methods'], true)) {
            return AuthResult::failed('invalid_method');
        }

        $throttle = 'mfa|' . $pending['user_id'];
        if (($wait = $this->throttled($throttle)) !== null) {
            return AuthResult::rateLimited($wait);
        }

        $code = trim($code);
        $valid = false;
        foreach ($method !== null ? [$method] : $pending['methods'] as $candidate) {
            $valid = match ($candidate) {
                'totp' => $this->totp()->verify($user, $code),
                'recovery' => $this->totp()->useRecoveryCode($user, $code),
                'otp' => $user instanceof NotifiableInterface
                    && ($email = $user->routeNotificationFor('email')) !== null
                    && $this->otp()->verify($email, $code, 'email'),
                default => false,
            };
            if ($valid) {
                break;
            }
        }

        if (!$valid) {
            $this->hitThrottle($throttle);

            return AuthResult::failed('invalid_code');
        }

        $this->clearThrottle($throttle);
        $this->login($user);

        return AuthResult::success($user);
    }

    /**
     * Mark the given user as authenticated, bypassing every check.
     * The session ID is regenerated to prevent session fixation.
     */
    public function login(AuthenticatableInterface $user): void
    {
        $session = $this->session();
        $session->regenerate(true);

        $now = $this->now();
        $session->forget($this->sessionKey('mfa_pending'));
        $session->put($this->sessionKey('user_id'), $user->getAuthIdentifier());
        $session->put($this->sessionKey('authenticated_at'), $now);
        $session->put($this->sessionKey('last_activity'), $now);

        $this->user = $user;
    }

    public function logout(): void
    {
        $session = $this->session();

        foreach (self::SESSION_ENTRIES as $entry) {
            $session->forget($this->sessionKey($entry));
        }
        $session->regenerate(true);

        $this->user = null;
    }

    public function check(): bool
    {
        return $this->id() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    /** ID of the authenticated user, or null. Enforces the idle timeout. */
    public function id(): int|string|null
    {
        $session = $this->session();
        $id = $session->get($this->sessionKey('user_id'));

        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        $now = $this->now();
        $lifetime = (int) $this->config->get('session.lifetime', 0) * 60;
        $lastActivity = (int) $session->get($this->sessionKey('last_activity'), $now);

        if ($lifetime > 0 && ($now - $lastActivity) > $lifetime) {
            $this->logout();

            return null;
        }

        $session->put($this->sessionKey('last_activity'), $now);

        return $id;
    }

    /** The authenticated user, or null for guests. */
    public function user(): ?AuthenticatableInterface
    {
        $id = $this->id();

        if ($id === null) {
            return $this->user = null;
        }

        if ($this->user !== null && (string) $this->user->getAuthIdentifier() === (string) $id) {
            return $this->user;
        }

        $user = $this->users()->findById($id);

        if ($user === null) {
            // The account no longer exists: drop the stale session.
            $this->logout();
        }

        return $this->user = $user;
    }

    /** Unix timestamp of the login that created the current session. */
    public function authenticatedAt(): ?int
    {
        if ($this->id() === null) {
            return null;
        }

        $at = $this->session()->get($this->sessionKey('authenticated_at'));

        return is_int($at) ? $at : null;
    }

    /**
     * @template T of AuthMethodInterface
     * @param class-string<T> $class
     * @return T
     */
    private function typed(string $name, string $class): AuthMethodInterface
    {
        $driver = $this->driver($name);

        if (!$driver instanceof $class) {
            throw new \LogicException(sprintf(
                'Driver "%s" was replaced by a custom %s; use Auth::driver(\'%s\') to access it.',
                $name,
                $driver::class,
                $name,
            ));
        }

        return $driver;
    }
}
