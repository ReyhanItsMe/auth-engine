<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\OAuth;

use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Contracts\AuthMethodInterface;
use ReyhanItsMe\AuthEngine\Contracts\MutableUserProviderInterface;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\AuthResult;
use ReyhanItsMe\AuthEngine\Core\Crypto;
use ReyhanItsMe\AuthEngine\Core\Identifier;
use ReyhanItsMe\AuthEngine\Exceptions\AuthEngineException;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;
use ReyhanItsMe\AuthEngine\Exceptions\OAuthException;
use ReyhanItsMe\AuthEngine\UI\SimpleIconsRegistry;

/**
 * OAuth 2.0 authorization-code flow (with state + PKCE where supported)
 * for the built-in and custom providers.
 */
final class OAuthManager implements AuthMethodInterface
{
    private const STATE_TTL = 600;
    private const APPLE_ISSUER = 'https://appleid.apple.com';

    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function name(): string
    {
        return 'oauth';
    }

    /**
     * Providers that have credentials configured, ready to render as buttons.
     *
     * @return list<array{key: string, name: string, color: string, icon: string, url: string}>
     */
    public function activeProviders(): array
    {
        $route = '/' . ltrim((string) $this->auth->config()->get('drivers.oauth.redirect_route', '/auth/oauth/{provider}'), '/');
        $active = [];

        foreach (array_keys((array) $this->auth->config()->get('drivers.oauth.providers', [])) as $key) {
            $key = (string) $key;
            $definition = $this->definition($key, false);
            if ($definition === null) {
                continue;
            }

            $icon = (string) ($definition['icon'] ?? $key);
            $active[] = [
                'key' => $key,
                'name' => (string) ($definition['name'] ?? ucfirst($key)),
                'color' => SimpleIconsRegistry::color($icon) ?? '#111827',
                'icon' => SimpleIconsRegistry::svg($icon) ?? '',
                'url' => $this->auth->adapter()->baseUrl() . str_replace('{provider}', rawurlencode($key), $route),
            ];
        }

        return $active;
    }

    /** URL to send the browser to. Stores the anti-CSRF state (and PKCE verifier) in the session. */
    public function redirectUrl(string $provider): string
    {
        $definition = $this->definition($provider);
        $state = Crypto::randomToken();
        $verifier = ($definition['pkce'] ?? false) === true ? Crypto::randomToken(48) : null;

        $this->auth->session()->put($this->auth->sessionKey('oauth_state'), [
            'provider' => $provider,
            'state' => $state,
            'verifier' => $verifier,
            'expires_at' => $this->auth->now() + self::STATE_TTL,
        ]);

        $params = [
            'response_type' => 'code',
            'client_id' => $definition['client_id'],
            'redirect_uri' => $this->redirectUri($provider, $definition),
            'scope' => implode((string) ($definition['scope_separator'] ?? ' '), (array) ($definition['scopes'] ?? [])),
            'state' => $state,
        ] + (array) ($definition['authorize_params'] ?? []);

        if ($verifier !== null) {
            $params['code_challenge'] = Crypto::base64UrlEncode(hash('sha256', $verifier, true));
            $params['code_challenge_method'] = 'S256';
        }

        $url = (string) $definition['authorize_url'];

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Handle the provider's redirect back to the application.
     *
     * - Guest: logs in the linked account, or links/creates one by e-mail.
     * - Logged-in user: links the provider account to the current user.
     *
     * @param array<array-key, mixed> $params the callback's query string ($_GET), or $_POST for Apple
     */
    public function callback(string $provider, array $params): AuthResult
    {
        $definition = $this->definition($provider);

        $session = $this->auth->session();
        $stored = $session->get($this->auth->sessionKey('oauth_state'));
        $session->forget($this->auth->sessionKey('oauth_state'));

        $state = $params['state'] ?? null;
        if (
            !is_array($stored)
            || !is_string($state)
            || ($stored['provider'] ?? null) !== $provider
            || !hash_equals((string) ($stored['state'] ?? ''), $state)
            || (int) ($stored['expires_at'] ?? 0) <= $this->auth->now()
        ) {
            return AuthResult::failed('invalid_state');
        }

        if (isset($params['error']) || !is_string($params['code'] ?? null) || $params['code'] === '') {
            return AuthResult::failed('access_denied');
        }

        try {
            $tokens = $this->exchangeCode($provider, $definition, $params['code'], $stored['verifier'] ?? null);
            $profile = $this->fetchUser($provider, $definition, $tokens, $params);
        } catch (OAuthException) {
            return AuthResult::failed('provider_error');
        }

        return $this->resolve($profile);
    }

    /**
     * @return list<array{provider: string, provider_user_id: string, email: ?string, name: ?string, avatar: ?string}>
     */
    public function accountsFor(AuthenticatableInterface $user): array
    {
        $db = $this->auth->db();

        return $db->all(
            "SELECT provider, provider_user_id, email, name, avatar FROM {$db->table('oauth_accounts')} WHERE user_id = ? ORDER BY id",
            [(string) $user->getAuthIdentifier()],
        );
    }

    public function unlink(AuthenticatableInterface $user, string $provider): bool
    {
        $db = $this->auth->db();

        return $db->run(
            "DELETE FROM {$db->table('oauth_accounts')} WHERE user_id = ? AND provider = ?",
            [(string) $user->getAuthIdentifier(), $provider],
        )->rowCount() > 0;
    }

    private function resolve(OAuthUser $profile): AuthResult
    {
        $db = $this->auth->db();
        $table = $db->table('oauth_accounts');
        $users = $this->auth->users();

        $link = $db->first("SELECT id, user_id FROM {$table} WHERE provider = ? AND provider_user_id = ?", [$profile->provider, $profile->id]);
        if ($link !== null) {
            $user = $users->findById((string) $link['user_id']);
            if ($user !== null) {
                if ($this->auth->check()) {
                    return (string) $this->auth->id() === (string) $link['user_id']
                        ? AuthResult::success($user)
                        : AuthResult::failed('account_already_linked');
                }

                return $this->auth->authenticate($user, 'oauth');
            }
            $db->run("DELETE FROM {$table} WHERE id = ?", [$link['id']]); // owner was deleted
        }

        $current = $this->auth->user();
        if ($current !== null) {
            $this->link($current, $profile);

            return AuthResult::success($current);
        }

        $user = $profile->email === null ? null : $users->findByIdentifier('email', $profile->email);

        if ($user !== null && !$profile->emailVerified) {
            // Never attach to an existing account on the strength of an unverified address.
            return AuthResult::failed('email_not_verified');
        }

        if ($user === null) {
            if ($profile->email === null && $this->auth->config()->get('drivers.oauth.require_email') !== false) {
                return AuthResult::failed('email_required');
            }

            if (!$users instanceof MutableUserProviderInterface) {
                throw new ConfigurationException('OAuth sign-up needs a user provider implementing MutableUserProviderInterface.');
            }

            $user = $users->create([
                'email' => $profile->email,
                'name' => mb_substr($profile->name ?? ($profile->email !== null ? (string) strstr($profile->email, '@', true) : 'User'), 0, 255),
                'password' => $this->auth->hasher()->unusable(),
                'email_verified_at' => $profile->emailVerified ? gmdate('Y-m-d H:i:s') : null,
            ]);
        }

        $this->link($user, $profile);

        return $this->auth->authenticate($user, 'oauth');
    }

    private function link(AuthenticatableInterface $user, OAuthUser $profile): void
    {
        $db = $this->auth->db();
        $db->insert($db->table('oauth_accounts'), [
            'user_id' => (string) $user->getAuthIdentifier(),
            'provider' => $profile->provider,
            'provider_user_id' => $profile->id,
            'email' => $profile->email,
            'name' => $profile->name === null ? null : mb_substr($profile->name, 0, 255),
            'avatar' => $profile->avatar,
            'created_at' => $this->auth->now(),
        ]);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<array-key, mixed> token endpoint response
     */
    private function exchangeCode(string $provider, array $definition, string $code, mixed $verifier): array
    {
        $secret = $provider === 'apple' && !isset($definition['client_secret'])
            ? $this->appleClientSecret($definition)
            : (string) ($definition['client_secret'] ?? '');

        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri($provider, $definition),
            'client_id' => (string) $definition['client_id'],
        ];
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

        if (($definition['token_auth'] ?? 'body') === 'basic') {
            $headers['Authorization'] = 'Basic ' . base64_encode(rawurlencode((string) $definition['client_id']) . ':' . rawurlencode($secret));
        } else {
            $body['client_secret'] = $secret;
        }

        if (is_string($verifier) && $verifier !== '') {
            $body['code_verifier'] = $verifier;
        }

        $tokens = $this->http('POST', (string) $definition['token_url'], $headers, http_build_query($body, '', '&'));

        if (!is_string($tokens['access_token'] ?? null) && !is_string($tokens['id_token'] ?? null)) {
            throw new OAuthException('The provider returned no token.');
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed>    $definition
     * @param array<array-key, mixed> $tokens
     * @param array<array-key, mixed> $params
     */
    private function fetchUser(string $provider, array $definition, array $tokens, array $params): OAuthUser
    {
        $map = (array) ($definition['map'] ?? []);

        if (($definition['userinfo_url'] ?? null) === null) {
            $raw = $this->idTokenClaims((string) ($tokens['id_token'] ?? ''), (string) $definition['client_id']);

            // Apple sends the name only once, as a JSON "user" field on the first authorization.
            $extra = is_string($params['user'] ?? null) ? json_decode($params['user'], true) : null;
            if (is_array($extra) && is_array($extra['name'] ?? null)) {
                $raw['name'] = trim(($extra['name']['firstName'] ?? '') . ' ' . ($extra['name']['lastName'] ?? ''));
            }
        } else {
            $auth = ['Authorization' => 'Bearer ' . (string) ($tokens['access_token'] ?? '')];
            $raw = $this->http('GET', (string) $definition['userinfo_url'], $auth);

            if (isset($definition['emails_url'])) {
                // GitHub: the profile e-mail may be hidden; the e-mails endpoint has the verified primary one.
                foreach ($this->http('GET', (string) $definition['emails_url'], $auth) as $entry) {
                    if (is_array($entry) && ($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true) {
                        $raw['email'] = $entry['email'] ?? null;
                        $map['email_verified'] = true;
                    }
                }
            }
        }

        $id = self::pick($raw, $map['id'] ?? 'id');
        if (!is_scalar($id) || (string) $id === '') {
            throw new OAuthException('The provider returned no user ID.');
        }

        $email = self::pick($raw, $map['email'] ?? null);
        $email = is_string($email) ? Identifier::normalize('email', $email) : null;

        $verified = $map['email_verified'] ?? false;
        $verified = is_bool($verified) ? $verified : self::pick($raw, $verified);

        $name = self::pick($raw, $map['name'] ?? null);
        $avatar = self::pick($raw, $map['avatar'] ?? null);

        if ($provider === 'discord' && is_string($raw['avatar'] ?? null)) {
            $avatar = sprintf('https://cdn.discordapp.com/avatars/%s/%s.png', rawurlencode((string) $id), rawurlencode($raw['avatar']));
        }

        return new OAuthUser(
            $provider,
            (string) $id,
            $email,
            $email !== null && ($verified === true || $verified === 'true' || $verified === 1),
            is_string($name) && trim($name) !== '' ? trim($name) : null,
            is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null,
            $raw,
        );
    }

    /**
     * Claims of an id_token received straight from the token endpoint over TLS
     * (OpenID Connect Core 3.1.3.7 allows skipping signature validation in that case).
     *
     * @return array<array-key, mixed>
     */
    private function idTokenClaims(string $jwt, string $clientId): array
    {
        $parts = explode('.', $jwt);
        $claims = count($parts) === 3 ? json_decode((string) Crypto::base64UrlDecode($parts[1]), true) : null;

        if (
            !is_array($claims)
            || ($claims['iss'] ?? null) !== self::APPLE_ISSUER
            || ($claims['aud'] ?? null) !== $clientId
            || (int) ($claims['exp'] ?? 0) <= $this->auth->now()
        ) {
            throw new OAuthException('Invalid id_token.');
        }

        return $claims;
    }

    /**
     * Apple's client secret is a short-lived ES256 JWT signed with the developer key.
     *
     * @param array<string, mixed> $definition
     */
    private function appleClientSecret(array $definition): string
    {
        foreach (['team_id', 'key_id', 'private_key'] as $required) {
            if (!is_string($definition[$required] ?? null) || $definition[$required] === '') {
                throw new ConfigurationException(sprintf('Apple sign-in needs "%s" (or a ready "client_secret").', $required));
            }
        }

        $now = $this->auth->now();
        $encode = static fn (array $data): string => Crypto::base64UrlEncode((string) json_encode($data));
        $input = $encode(['alg' => 'ES256', 'kid' => $definition['key_id'], 'typ' => 'JWT']) . '.' . $encode([
            'iss' => $definition['team_id'],
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => self::APPLE_ISSUER,
            'sub' => $definition['client_id'],
        ]);

        if (!openssl_sign($input, $der, (string) $definition['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new ConfigurationException('Apple "private_key" is not a valid EC private key.');
        }

        return $input . '.' . Crypto::base64UrlEncode(self::derToRaw($der));
    }

    /** Convert an ASN.1 DER ECDSA signature into the fixed 64-byte R||S form JWTs use. */
    public static function derToRaw(string $der): string
    {
        $offset = (ord($der[1]) & 0x80) !== 0 ? 2 + (ord($der[1]) & 0x7F) : 2;
        $raw = '';

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $raw .= str_pad(ltrim(substr($der, $offset + 2, $length), "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }

    /**
     * @param array<string, string> $headers
     * @return array<array-key, mixed>
     */
    private function http(string $method, string $url, array $headers, ?string $body = null): array
    {
        try {
            $response = $this->auth->http()->request($method, $url, $headers, $body);
        } catch (AuthEngineException $e) {
            throw new OAuthException('The provider could not be reached.', 0, $e);
        }

        if (!$response->ok()) {
            throw new OAuthException(sprintf('The provider answered with HTTP %d.', $response->status));
        }

        return $response->json();
    }

    /**
     * Built-in definition merged with the configured values.
     *
     * @return ($strict is true ? array<string, mixed> : array<string, mixed>|null)
     */
    private function definition(string $provider, bool $strict = true): ?array
    {
        $configured = $this->auth->config()->get('drivers.oauth.providers.' . $provider);
        $definition = is_array($configured) ? $configured + (Providers::get($provider) ?? []) : null;

        $usable = $definition !== null
            && is_string($definition['client_id'] ?? null) && $definition['client_id'] !== ''
            && is_string($definition['authorize_url'] ?? null) && str_starts_with($definition['authorize_url'], 'https://')
            && is_string($definition['token_url'] ?? null) && str_starts_with($definition['token_url'], 'https://');

        if ($usable) {
            return $definition;
        }

        if ($strict) {
            throw new ConfigurationException(sprintf(
                'OAuth provider "%s" is not configured. Add it under "drivers.oauth.providers" with at least a client_id.',
                $provider,
            ));
        }

        return null;
    }

    /** @param array<string, mixed> $definition */
    private function redirectUri(string $provider, array $definition): string
    {
        if (is_string($definition['redirect_uri'] ?? null) && $definition['redirect_uri'] !== '') {
            return $definition['redirect_uri'];
        }

        $route = '/' . ltrim((string) $this->auth->config()->get('drivers.oauth.callback', '/auth/oauth/{provider}/callback'), '/');

        return $this->auth->adapter()->baseUrl() . str_replace('{provider}', rawurlencode($provider), $route);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param string|list<string>|null $paths
     */
    private static function pick(array $data, mixed $paths): mixed
    {
        foreach ((array) $paths as $path) {
            $cursor = $data;
            foreach (explode('.', (string) $path) as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$segment];
            }
            if ($cursor !== null && $cursor !== '') {
                return $cursor;
            }
        }

        return null;
    }
}
