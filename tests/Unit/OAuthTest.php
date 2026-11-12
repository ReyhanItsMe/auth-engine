<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Unit;

use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\Crypto;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;
use ReyhanItsMe\AuthEngine\OAuth\OAuthManager;
use ReyhanItsMe\AuthEngine\OAuth\Providers;
use ReyhanItsMe\AuthEngine\Tests\Support\EngineTestCase;
use ReyhanItsMe\AuthEngine\Tests\Support\FakeHttpClient;
use ReyhanItsMe\AuthEngine\UI\SimpleIconsRegistry;

final class OAuthTest extends EngineTestCase
{
    private FakeHttpClient $http;

    /**
     * @param array<string, array<string, mixed>> $providers
     * @param array<string, mixed> $oauth
     */
    private function oauth(array $providers = [], array $oauth = []): AuthManager
    {
        $auth = $this->engine([
            'registration' => ['auto_login' => false],
            'drivers' => ['oauth' => ['enabled' => true, 'providers' => $providers ?: [
                'google' => ['client_id' => 'g-id', 'client_secret' => 'g-secret'],
                'github' => ['client_id' => 'gh-id', 'client_secret' => 'gh-secret'],
            ]] + $oauth],
        ]);
        $this->http ??= new FakeHttpClient();
        $auth->setHttpClient($this->http);

        return $auth;
    }

    /** @return array<string, string> query parameters of the authorization URL */
    private function begin(AuthManager $auth, string $provider): array
    {
        parse_str((string) parse_url($auth->oauth()->redirectUrl($provider), PHP_URL_QUERY), $query);

        return $query;
    }

    /** @param array<string, mixed> $profile */
    private function google(array $profile): void
    {
        $this->http->responses = [
            'https://oauth2.googleapis.com/token' => [200, ['access_token' => 'at-1']],
            'https://openidconnect.googleapis.com/v1/userinfo' => [200, $profile],
        ];
    }

    public function testRedirectUrlCarriesStateAndPkce(): void
    {
        $auth = $this->oauth();
        $url = $auth->oauth()->redirectUrl('google');
        $query = $this->begin($auth, 'google');

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        self::assertSame('code', $query['response_type']);
        self::assertSame('g-id', $query['client_id']);
        self::assertSame('https://app.test/auth/oauth/google/callback', $query['redirect_uri']);
        self::assertSame('openid email profile', $query['scope']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertGreaterThanOrEqual(43, strlen($query['state']));
        self::assertStringNotContainsString('g-secret', $url);
        self::assertNotSame($query['state'], $this->begin($auth, 'google')['state']);

        $fb = $this->oauth(['facebook' => ['client_id' => 'f', 'client_secret' => 's', 'scopes' => ['email']]]);
        self::assertArrayNotHasKey('code_challenge', $this->begin($fb, 'facebook'));
    }

    public function testCallbackCreatesAccountThenReusesIt(): void
    {
        $auth = $this->oauth();
        $this->google(['sub' => '1001', 'email' => 'Jane@Example.com', 'email_verified' => true, 'name' => 'Jane', 'picture' => 'https://img.test/j.png']);

        $query = $this->begin($auth, 'google');
        $result = $auth->oauth()->callback('google', ['state' => $query['state'], 'code' => 'c-1']);

        self::assertTrue($result->ok());
        self::assertSame('jane@example.com', $auth->user()?->get('email'));
        self::assertSame('Jane', $auth->user()?->get('name'));
        self::assertNotNull($auth->user()?->get('email_verified_at'));

        $form = $this->http->form(0);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('c-1', $form['code']);
        self::assertSame('g-secret', $form['client_secret']);
        self::assertSame($query['code_challenge'], Crypto::base64UrlEncode(hash('sha256', $form['code_verifier'], true)), 'PKCE verifier matches the challenge');
        self::assertSame('Bearer at-1', $this->http->requests[1]['headers']['Authorization']);

        self::assertSame(
            [['provider' => 'google', 'provider_user_id' => '1001', 'email' => 'jane@example.com', 'name' => 'Jane', 'avatar' => 'https://img.test/j.png']],
            $auth->oauth()->accountsFor($auth->user()),
        );
        $id = $auth->id();
        $auth->logout();

        // Second visit, and the e-mail changed at the provider: still the same local account.
        $this->google(['sub' => '1001', 'email' => 'new@example.com', 'email_verified' => true]);
        $query = $this->begin($auth, 'google');
        self::assertTrue($auth->oauth()->callback('google', ['state' => $query['state'], 'code' => 'c-2'])->ok());
        self::assertSame($id, $auth->id());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testStateIsVerifiedAndSingleUse(): void
    {
        $auth = $this->oauth();
        $this->google(['sub' => '1', 'email' => 'a@b.co', 'email_verified' => true]);

        self::assertSame('invalid_state', $auth->oauth()->callback('google', ['state' => 'x', 'code' => 'c'])->reason, 'no flow started');

        $query = $this->begin($auth, 'google');
        self::assertSame('invalid_state', $auth->oauth()->callback('google', ['state' => 'forged', 'code' => 'c'])->reason);
        self::assertSame('invalid_state', $auth->oauth()->callback('google', ['state' => $query['state'], 'code' => 'c'])->reason, 'burned by the forged attempt');

        $query = $this->begin($auth, 'google');
        self::assertSame('invalid_state', $auth->oauth()->callback('github', ['state' => $query['state'], 'code' => 'c'])->reason, 'bound to the provider');

        $query = $this->begin($auth, 'google');
        $this->now += 601;
        self::assertSame('invalid_state', $auth->oauth()->callback('google', ['state' => $query['state'], 'code' => 'c'])->reason, 'expired');

        $query = $this->begin($auth, 'google');
        self::assertSame('access_denied', $auth->oauth()->callback('google', ['state' => $query['state'], 'error' => 'access_denied'])->reason);

        self::assertSame([], $this->http->requests, 'no request leaves the server for a bad callback');
        self::assertTrue($auth->guest());
    }

    public function testProviderErrorsDoNotLogIn(): void
    {
        $auth = $this->oauth();

        $this->http->responses = ['https://oauth2.googleapis.com/token' => [400, ['error' => 'invalid_grant']]];
        $query = $this->begin($auth, 'google');
        self::assertSame('provider_error', $auth->oauth()->callback('google', ['state' => $query['state'], 'code' => 'c'])->reason);

        $this->google(['email' => 'no-id@example.com']);
        $query = $this->begin($auth, 'google');
        self::assertSame('provider_error', $auth->oauth()->callback('google', ['state' => $query['state'], 'code' => 'c'])->reason);
        self::assertTrue($auth->guest());
    }

    public function testExistingAccountIsLinkedOnlyThroughAVerifiedEmail(): void
    {
        $auth = $this->oauth();
        $jane = $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);

        $this->google(['sub' => 'attacker', 'email' => 'jane@example.com', 'email_verified' => false]);
        $query = $this->begin($auth, 'google');
        $result = $auth->oauth()->callback('google', ['state' => $query['state'], 'code' => 'c']);
        self::assertSame('email_not_verified', $result->reason, 'an unverified address must not take over an account');
        self::assertTrue($auth->guest());
        self::assertSame([], $auth->oauth()->accountsFor($jane));

        $this->google(['sub' => 'real', 'email' => 'jane@example.com', 'email_verified' => 'true']);
        $query = $this->begin($auth, 'google');
        self::assertTrue($auth->oauth()->callback('google', ['state' => $query['state'], 'code' => 'c'])->ok());
        self::assertSame($jane->getAuthIdentifier(), $auth->id());
    }

    public function testGithubUsesTheVerifiedPrimaryEmail(): void
    {
        $auth = $this->oauth();
        $this->http->responses = [
            'https://github.com/login/oauth/access_token' => [200, ['access_token' => 'gh-at']],
            'https://api.github.com/user/emails' => [200, [
                ['email' => 'old@example.com', 'primary' => false, 'verified' => true],
                ['email' => 'octo@example.com', 'primary' => true, 'verified' => true],
            ]],
            'https://api.github.com/user' => [200, ['id' => 583231, 'login' => 'octocat', 'name' => null, 'email' => null, 'avatar_url' => 'https://avatars.test/u/1']],
        ];

        $query = $this->begin($auth, 'github');
        self::assertSame('read:user user:email', $query['scope']);
        self::assertTrue($auth->oauth()->callback('github', ['state' => $query['state'], 'code' => 'c'])->ok());
        self::assertSame('octo@example.com', $auth->user()?->get('email'));
        self::assertSame('octocat', $auth->user()?->get('name'), 'falls back to the login when the name is empty');
        self::assertSame('583231', $auth->oauth()->accountsFor($auth->user())[0]['provider_user_id']);
    }

    public function testLoggedInUserLinksAndUnlinksAProvider(): void
    {
        $auth = $this->oauth();
        $jane = $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);
        $john = $auth->register(['email' => 'john@example.com', 'password' => 'password1']);
        $auth->login($jane);

        $this->google(['sub' => '77', 'email' => 'work@corp.test', 'email_verified' => false]);
        $query = $this->begin($auth, 'google');
        self::assertTrue($auth->oauth()->callback('google', ['state' => $query['state'], 'code' => 'c'])->ok());
        self::assertSame($jane->getAuthIdentifier(), $auth->id());
        self::assertSame('work@corp.test', $auth->oauth()->accountsFor($jane)[0]['email']);

        $auth->login($john);
        $query = $this->begin($auth, 'google');
        self::assertSame('account_already_linked', $auth->oauth()->callback('google', ['state' => $query['state'], 'code' => 'c'])->reason);

        self::assertTrue($auth->oauth()->unlink($jane, 'google'));
        self::assertFalse($auth->oauth()->unlink($jane, 'google'));
    }

    public function testProvidersWithoutEmailNeedOptIn(): void
    {
        $x = ['x' => ['client_id' => 'x-id', 'client_secret' => 'x-secret']];
        $this->http = new FakeHttpClient([
            'https://api.x.com/2/oauth2/token' => [200, ['access_token' => 'x-at']],
            'https://api.x.com/2/users/me' => [200, ['data' => ['id' => '42', 'name' => 'Jane X', 'username' => 'janex', 'profile_image_url' => 'https://pbs.test/a.jpg']]],
        ]);

        $auth = $this->oauth($x);
        $query = $this->begin($auth, 'x');
        self::assertSame('email_required', $auth->oauth()->callback('x', ['state' => $query['state'], 'code' => 'c'])->reason);
        self::assertSame('Basic ' . base64_encode('x-id:x-secret'), $this->http->requests[0]['headers']['Authorization']);
        self::assertArrayNotHasKey('client_secret', $this->http->form(0));

        $open = $this->oauth($x, ['require_email' => false]);
        $query = $this->begin($open, 'x');
        self::assertTrue($open->oauth()->callback('x', ['state' => $query['state'], 'code' => 'c'])->ok());
        self::assertSame('Jane X', $open->user()?->get('name'));
        self::assertNull($open->user()?->get('email'));
    }

    public function testAppleSignsItsClientSecretAndReadsTheIdToken(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $auth = $this->oauth(['apple' => ['client_id' => 'com.acme.web', 'team_id' => 'TEAM123', 'key_id' => 'KEY456', 'private_key' => $pem]]);

        $jwt = fn (array $claims): string => 'e30.' . Crypto::base64UrlEncode((string) json_encode($claims)) . '.sig';
        $claims = ['iss' => 'https://appleid.apple.com', 'aud' => 'com.acme.web', 'exp' => $this->now + 600, 'sub' => 'apple-1', 'email' => 'jane@privaterelay.appleid.com', 'email_verified' => 'true'];
        $this->http->responses = ['https://appleid.apple.com/auth/token' => [200, ['id_token' => $jwt($claims)]]];

        $query = $this->begin($auth, 'apple');
        self::assertSame('form_post', $query['response_mode']);
        self::assertSame('name email', $query['scope']);

        $result = $auth->oauth()->callback('apple', [
            'state' => $query['state'],
            'code' => 'c',
            'user' => '{"name":{"firstName":"Jane","lastName":"Appleseed"}}',
        ]);
        self::assertTrue($result->ok());
        self::assertSame('Jane Appleseed', $auth->user()?->get('name'));
        self::assertCount(1, $this->http->requests, 'no user-info request: the profile comes from the id_token');

        // The generated client secret must be a valid ES256 JWT for Apple.
        [$header, $payload, $signature] = explode('.', $this->http->form(0)['client_secret']);
        self::assertSame(['alg' => 'ES256', 'kid' => 'KEY456', 'typ' => 'JWT'], json_decode((string) Crypto::base64UrlDecode($header), true));
        self::assertSame(
            ['iss' => 'TEAM123', 'iat' => $this->now, 'exp' => $this->now + 300, 'aud' => 'https://appleid.apple.com', 'sub' => 'com.acme.web'],
            json_decode((string) Crypto::base64UrlDecode($payload), true),
        );
        $raw = (string) Crypto::base64UrlDecode($signature);
        self::assertSame(64, strlen($raw));
        $int = static fn (string $b): string => "\x02" . chr(strlen($b = (ord(ltrim($b, "\x00")[0]) & 0x80 ? "\x00" : '') . ltrim($b, "\x00"))) . $b;
        $der = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        self::assertSame(1, openssl_verify("$header.$payload", "\x30" . chr(strlen($der)) . $der, openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));
        self::assertSame($raw, OAuthManager::derToRaw("\x30" . chr(strlen($der)) . $der));
        $auth->logout();

        foreach ([['aud' => 'someone-else'], ['iss' => 'https://evil.test'], ['exp' => $this->now - 1]] as $bad) {
            $this->http->responses = ['https://appleid.apple.com/auth/token' => [200, ['id_token' => $jwt($bad + $claims)]]];
            $query = $this->begin($auth, 'apple');
            self::assertSame('provider_error', $auth->oauth()->callback('apple', ['state' => $query['state'], 'code' => 'c'])->reason, json_encode($bad));
        }
    }

    public function testActiveProvidersExposeSimpleIconsSvg(): void
    {
        $auth = $this->oauth([
            'google' => ['client_id' => 'g', 'client_secret' => 's'],
            'github' => ['client_id' => '', 'client_secret' => ''],
            'discord' => ['client_id' => 'd', 'client_secret' => 's'],
            'acme' => [
                'client_id' => 'a', 'client_secret' => 's', 'name' => 'Acme SSO',
                'authorize_url' => 'https://sso.acme.test/authorize', 'token_url' => 'https://sso.acme.test/token',
                'userinfo_url' => 'https://sso.acme.test/me', 'scopes' => ['profile'],
                'map' => ['id' => 'uid', 'email' => 'mail', 'email_verified' => true, 'name' => 'cn'],
            ],
            'unknown' => ['client_id' => 'u'],
        ]);

        $providers = $auth->getActiveProviders();
        self::assertSame(['google', 'discord', 'acme'], array_column($providers, 'key'), 'only configured, usable providers');

        $google = $providers[0];
        self::assertSame('Google', $google['name']);
        self::assertSame('#4285F4', $google['color']);
        self::assertSame('https://app.test/auth/oauth/google', $google['url']);
        self::assertStringStartsWith('<svg role="img" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><title>Google</title><path d="M12.48 10.92v3.28h7.84', $google['icon']);
        self::assertStringEndsWith('"/></svg>', $google['icon']);

        self::assertSame(['key' => 'acme', 'name' => 'Acme SSO', 'color' => '#111827', 'icon' => '', 'url' => 'https://app.test/auth/oauth/acme'], $providers[2]);

        // The custom provider works end to end through the generic mapping.
        $this->http->responses = [
            'https://sso.acme.test/token' => [200, ['access_token' => 't']],
            'https://sso.acme.test/me' => [200, ['uid' => 9, 'mail' => 'emp@acme.test', 'cn' => 'Employee']],
        ];
        $query = $this->begin($auth, 'acme');
        self::assertTrue($auth->oauth()->callback('acme', ['state' => $query['state'], 'code' => 'c'])->ok());
        self::assertSame('emp@acme.test', $auth->user()?->get('email'));

        $this->expectException(ConfigurationException::class);
        $auth->oauth()->redirectUrl('github');
    }

    public function testSimpleIconsRegistry(): void
    {
        foreach (array_keys(Providers::all()) as $provider) {
            self::assertTrue(SimpleIconsRegistry::has($provider), "icon for $provider");
            self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', (string) SimpleIconsRegistry::color($provider));
            $definition = Providers::get($provider);
            self::assertStringStartsWith('https://', $definition['authorize_url']);
            self::assertStringStartsWith('https://', $definition['token_url']);
        }

        $github = SimpleIconsRegistry::get('github');
        self::assertSame(['slug', 'title', 'hex', 'path', 'svg'], array_keys($github));
        self::assertSame('GitHub', $github['title']);
        self::assertSame('181717', $github['hex']);
        self::assertStringStartsWith('M12 .297c-6.63 0-12 5.373-12 12', $github['path']);

        $svg = SimpleIconsRegistry::svg('github', ['class' => 'h-5 w-5', 'fill' => 'currentColor', 'on"click' => 'x', 'aria-label' => '"><script>']);
        self::assertStringContainsString(' class="h-5 w-5" fill="currentColor" aria-label="&quot;&gt;&lt;script&gt;">', $svg);
        self::assertStringNotContainsString('on"click', $svg);

        self::assertNull(SimpleIconsRegistry::get('nope'));
        self::assertNull(SimpleIconsRegistry::svg('nope'));

        SimpleIconsRegistry::register('acme-test', 'Acme', 'ff0000', 'M0 0h24v24H0z');
        self::assertSame('#FF0000', SimpleIconsRegistry::color('acme-test'));
        self::assertContains('acme-test', SimpleIconsRegistry::slugs());

        $this->expectException(\InvalidArgumentException::class);
        SimpleIconsRegistry::register('evil', 'Evil', '000000', 'M0 0"/><script>alert(1)</script>');
    }
}
