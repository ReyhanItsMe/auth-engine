<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Unit;

use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\Crypto;
use ReyhanItsMe\AuthEngine\Exceptions\WebAuthnException;
use ReyhanItsMe\AuthEngine\Methods\Totp\Totp;
use ReyhanItsMe\AuthEngine\Methods\WebAuthn\CborDecoder;
use ReyhanItsMe\AuthEngine\Methods\WebAuthn\CoseKey;
use ReyhanItsMe\AuthEngine\Tests\Support\EngineTestCase;
use ReyhanItsMe\AuthEngine\Tests\Support\FakeAuthenticator;

final class WebAuthnTest extends EngineTestCase
{
    private AuthenticatableInterface $user;

    /** @param array<string, mixed> $webauthn */
    private function passkeys(array $webauthn = [], bool $totp = false): AuthManager
    {
        $auth = $this->engine([
            'registration' => ['auto_login' => false],
            'drivers' => ['webauthn' => ['enabled' => true] + $webauthn, 'totp' => ['enabled' => $totp]],
        ]);
        $this->user = $auth->users()->findByIdentifier('email', 'jane@example.com')
            ?? $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);

        return $auth;
    }

    private function enroll(AuthManager $auth, FakeAuthenticator $key, string $name = 'MacBook'): void
    {
        $options = $auth->webauthn()->registrationOptions($this->user);
        $auth->webauthn()->register($this->user, $key->attestation($options['challenge']), $name);
    }

    public function testRegistrationOptionsFollowTheSpec(): void
    {
        $auth = $this->passkeys();
        $options = $auth->webauthn()->registrationOptions($this->user, 'Jane');

        self::assertSame(['id' => 'app.test', 'name' => 'Acme'], $options['rp']);
        self::assertSame((string) $this->user->getAuthIdentifier(), Crypto::base64UrlDecode($options['user']['id']));
        self::assertSame('jane@example.com', $options['user']['name']);
        self::assertSame('Jane', $options['user']['displayName']);
        self::assertSame([-7, -257], array_column($options['pubKeyCredParams'], 'alg'));
        self::assertSame('none', $options['attestation']);
        self::assertSame(32, strlen((string) Crypto::base64UrlDecode($options['challenge'])));
        self::assertNotSame($options['challenge'], $auth->webauthn()->registrationOptions($this->user)['challenge']);
    }

    public function testRegisterAndLoginWithEs256Passkey(): void
    {
        $auth = $this->passkeys();
        $key = new FakeAuthenticator();
        $this->enroll($auth, $key);

        $list = $auth->webauthn()->passkeys($this->user);
        self::assertCount(1, $list);
        self::assertSame('MacBook', $list[0]['name']);
        self::assertNull($list[0]['last_used_at']);
        self::assertSame(
            [['type' => 'public-key', 'id' => Crypto::base64UrlEncode($key->credentialId), 'transports' => ['internal']]],
            $auth->webauthn()->registrationOptions($this->user)['excludeCredentials'],
        );

        // Usernameless: no user is named when the options are requested.
        $options = $auth->webauthn()->authenticationOptions();
        self::assertSame('app.test', $options['rpId']);
        self::assertSame([], $options['allowCredentials']);

        $before = $this->session->getId();
        $result = $auth->webauthn()->authenticate($key->assertion($options['challenge']));

        self::assertTrue($result->ok());
        self::assertSame($this->user->getAuthIdentifier(), $auth->id());
        self::assertNotSame($before, $this->session->getId());
        self::assertSame($this->now, $auth->webauthn()->passkeys($this->user)[0]['last_used_at']);
    }

    public function testRs256PasskeyAndDeletion(): void
    {
        $auth = $this->passkeys();
        $key = new FakeAuthenticator(type: 'rsa');
        $this->enroll($auth, $key, 'Windows Hello');

        $options = $auth->webauthn()->authenticationOptions($this->user);
        self::assertCount(1, $options['allowCredentials']);
        self::assertTrue($auth->webauthn()->authenticate($key->assertion($options['challenge']))->ok());
        $auth->logout();

        $id = $auth->webauthn()->passkeys($this->user)[0]['id'];
        self::assertTrue($auth->webauthn()->delete($this->user, $id));
        self::assertFalse($auth->webauthn()->delete($this->user, $id));

        $options = $auth->webauthn()->authenticationOptions();
        self::assertSame('invalid_passkey', $auth->webauthn()->authenticate($key->assertion($options['challenge']))->reason);
    }

    public function testChallengeIsSingleUseAndExpires(): void
    {
        $auth = $this->passkeys();
        $key = new FakeAuthenticator();
        $this->enroll($auth, $key);

        $options = $auth->webauthn()->authenticationOptions();
        $assertion = $key->assertion($options['challenge']);
        self::assertTrue($auth->webauthn()->authenticate($assertion)->ok());
        $auth->logout();
        self::assertFalse($auth->webauthn()->authenticate($assertion)->ok(), 'a captured response cannot be replayed');

        $options = $auth->webauthn()->authenticationOptions();
        $this->now += 301;
        self::assertFalse($auth->webauthn()->authenticate($key->assertion($options['challenge']))->ok());

        self::assertFalse($auth->webauthn()->authenticate($key->assertion('made-up-challenge'))->ok(), 'no challenge issued');
    }

    public function testForgedResponsesAreRejected(): void
    {
        $auth = $this->passkeys();
        $key = new FakeAuthenticator();
        $this->enroll($auth, $key);

        $attempt = function (callable $forge) use ($auth, $key): bool {
            $options = $auth->webauthn()->authenticationOptions();

            return $auth->webauthn()->authenticate($forge($options['challenge'], $key))->ok();
        };

        // Phishing site: right key, wrong origin.
        $phish = new FakeAuthenticator('app.test', 'https://evil.test');
        $phish->credentialId = $key->credentialId;
        self::assertFalse($attempt(fn (string $c) => $phish->assertion($c)));

        // A different key claiming the registered credential ID.
        $impostor = new FakeAuthenticator();
        $impostor->credentialId = $key->credentialId;
        self::assertFalse($attempt(fn (string $c) => $impostor->assertion($c)));

        // Passkey scoped to another relying party.
        $other = new FakeAuthenticator('other.test');
        $other->credentialId = $key->credentialId;
        self::assertFalse($attempt(fn (string $c) => $other->assertion($c)));

        // Wrong ceremony type, missing user presence, tampered signature, junk.
        self::assertFalse($attempt(fn (string $c, FakeAuthenticator $k) => $k->attestation($c)));
        self::assertFalse($attempt(fn (string $c, FakeAuthenticator $k) => $k->assertion($c, 0x00)));
        self::assertFalse($attempt(function (string $c, FakeAuthenticator $k): array {
            $a = $k->assertion($c);
            $a['response']['signature'] = Crypto::base64UrlEncode(random_bytes(70));

            return $a;
        }));
        self::assertFalse($attempt(fn () => ['id' => '***', 'response' => 'nope']));
        self::assertFalse($attempt(fn () => []));

        self::assertTrue($auth->guest());
        self::assertTrue($attempt(fn (string $c, FakeAuthenticator $k) => $k->assertion($c)), 'the real key still works');
    }

    public function testClonedAuthenticatorIsDetectedByCounter(): void
    {
        $auth = $this->passkeys();
        $key = new FakeAuthenticator();
        $this->enroll($auth, $key);

        $key->counter = 10;
        $options = $auth->webauthn()->authenticationOptions();
        self::assertTrue($auth->webauthn()->authenticate($key->assertion($options['challenge']))->ok());
        $auth->logout();

        $key->counter = 4; // a clone that is behind the real device
        $options = $auth->webauthn()->authenticationOptions();
        self::assertFalse($auth->webauthn()->authenticate($key->assertion($options['challenge']))->ok());
    }

    public function testPasskeyOfAnotherUserCannotAnswerATargetedChallenge(): void
    {
        $auth = $this->passkeys();
        $key = new FakeAuthenticator();
        $this->enroll($auth, $key);
        $john = $auth->register(['email' => 'john@example.com', 'password' => 'password1']);

        $options = $auth->webauthn()->authenticationOptions($john);
        self::assertSame([], $options['allowCredentials']);
        self::assertFalse($auth->webauthn()->authenticate($key->assertion($options['challenge']))->ok());
    }

    public function testRegistrationFailures(): void
    {
        $auth = $this->passkeys(['user_verification' => 'required']);
        $key = new FakeAuthenticator();
        $john = $auth->register(['email' => 'john@example.com', 'password' => 'password1']);

        $failures = [
            'no challenge' => fn () => $auth->webauthn()->register($this->user, $key->attestation('x')),
            'challenge of another user' => function () use ($auth, $key, $john): void {
                $options = $auth->webauthn()->registrationOptions($this->user);
                $auth->webauthn()->register($john, $key->attestation($options['challenge']));
            },
            'user verification required' => function () use ($auth, $key): void {
                $options = $auth->webauthn()->registrationOptions($this->user);
                $auth->webauthn()->register($this->user, $key->attestation($options['challenge'], 0x41));
            },
            'wrong origin' => function () use ($auth): void {
                $options = $auth->webauthn()->registrationOptions($this->user);
                $auth->webauthn()->register($this->user, (new FakeAuthenticator('app.test', 'https://evil.test'))->attestation($options['challenge']));
            },
            'garbage attestation' => function () use ($auth, $key): void {
                $options = $auth->webauthn()->registrationOptions($this->user);
                $credential = $key->attestation($options['challenge']);
                $credential['response']['attestationObject'] = Crypto::base64UrlEncode("\xA1\x63fmt");
                $auth->webauthn()->register($this->user, $credential);
            },
        ];

        foreach ($failures as $label => $failure) {
            try {
                $failure();
                self::fail("Expected WebAuthnException: $label");
            } catch (WebAuthnException) {
            }
        }
        self::assertSame([], $auth->webauthn()->passkeys($this->user));

        $this->enroll($auth, $key);
        try {
            $this->enroll($auth, $key);
            self::fail('The same passkey cannot be registered twice.');
        } catch (WebAuthnException $e) {
            self::assertStringContainsString('already registered', $e->getMessage());
        }
    }

    public function testCustomOriginsAndRpId(): void
    {
        $auth = $this->passkeys(['rp_id' => 'example.com', 'origins' => ['https://login.example.com/', 'https://example.com']]);
        $key = new FakeAuthenticator('example.com', 'https://login.example.com');
        $this->enroll($auth, $key);

        $options = $auth->webauthn()->authenticationOptions();
        self::assertSame('example.com', $options['rpId']);
        self::assertTrue($auth->webauthn()->authenticate($key->assertion($options['challenge']))->ok());
    }

    public function testUserVerifiedPasskeySatisfiesMfaButPresenceOnlyDoesNot(): void
    {
        $auth = $this->passkeys(totp: true);
        $key = new FakeAuthenticator();
        $this->enroll($auth, $key);

        $setup = $auth->totp()->beginSetup($this->user);
        $auth->totp()->confirm($this->user, Totp::code($setup['secret'], $this->now));
        $this->now += 30;

        $options = $auth->webauthn()->authenticationOptions();
        self::assertTrue($auth->webauthn()->authenticate($key->assertion($options['challenge'], 0x05))->ok(), 'biometric/PIN = two factors');
        $auth->logout();

        $options = $auth->webauthn()->authenticationOptions();
        $result = $auth->webauthn()->authenticate($key->assertion($options['challenge'], 0x01));
        self::assertTrue($result->requiresMfa(), 'a touch-only key is a single factor');
        self::assertTrue($auth->verifyMfa(Totp::code($setup['secret'], $this->now))->ok());
    }

    public function testCborDecoderAndCoseKeyEdgeCases(): void
    {
        self::assertSame([1 => 2, 'a' => [true, false, null, -257, 'hi']], CborDecoder::decode("\xA2\x01\x02\x61a\x85\xF5\xF4\xF6\x39\x01\x00\x62hi"));
        self::assertSame([500, 3], CborDecoder::decodeFirst("\x19\x01\xF4\xFF\xFF"));
        self::assertSame(65536, CborDecoder::decode("\x1A\x00\x01\x00\x00"));

        foreach (["\x58\x20short", "\x9F\x01\xFF", "\xFB\x00", '', str_repeat("\x81", 12) . "\x01"] as $bad) {
            try {
                CborDecoder::decode($bad);
                self::fail('Expected WebAuthnException for ' . bin2hex($bad));
            } catch (WebAuthnException) {
            }
        }

        $ec = CborDecoder::decode((new FakeAuthenticator())->cose());
        self::assertNotFalse(openssl_pkey_get_public(CoseKey::toPem($ec)));
        $rsa = CborDecoder::decode((new FakeAuthenticator(type: 'rsa'))->cose());
        self::assertSame(2048, openssl_pkey_get_details(openssl_pkey_get_public(CoseKey::toPem($rsa)))['bits']);

        foreach ([[], [1 => 2, 3 => -8], [1 => 2, 3 => -7, -1 => 2, -2 => $ec[-2], -3 => $ec[-3]], [1 => 2, 3 => -7, -1 => 1, -2 => 'x', -3 => 'y']] as $bad) {
            try {
                CoseKey::toPem($bad);
                self::fail('Expected WebAuthnException.');
            } catch (WebAuthnException) {
            }
        }
    }
}
