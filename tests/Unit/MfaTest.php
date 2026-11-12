<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Unit;

use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\AuthStatus;
use ReyhanItsMe\AuthEngine\Exceptions\AuthEngineException;
use ReyhanItsMe\AuthEngine\Methods\Totp\Base32;
use ReyhanItsMe\AuthEngine\Methods\Totp\Totp;
use ReyhanItsMe\AuthEngine\Tests\Support\EngineTestCase;

final class MfaTest extends EngineTestCase
{
    private AuthenticatableInterface $user;

    /** @param array<string, mixed> $overrides */
    private function totp(array $overrides = []): AuthManager
    {
        $auth = $this->engine($overrides + [
            'registration' => ['auto_login' => false],
            'drivers' => ['totp' => ['enabled' => true]],
        ]);
        $this->user = $auth->users()->findByIdentifier('email', 'jane@example.com')
            ?? $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);

        return $auth;
    }

    /** @return array{0: string, 1: list<string>} secret and recovery codes */
    private function enroll(AuthManager $auth): array
    {
        $setup = $auth->totp()->beginSetup($this->user);
        $codes = $auth->totp()->confirm($this->user, Totp::code($setup['secret'], $this->now));
        self::assertIsArray($codes);
        $this->now += 30;

        return [$setup['secret'], $codes];
    }

    public function testRfc6238VectorsAndBase32(): void
    {
        $secret = Base32::encode('12345678901234567890');
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        self::assertSame('12345678901234567890', Base32::decode(strtolower($secret)));
        self::assertNull(Base32::decode('not base32 !'));

        self::assertSame('94287082', Totp::code($secret, 59, 30, 8));
        self::assertSame('07081804', Totp::code($secret, 1111111109, 30, 8));
        self::assertSame('14050471', Totp::code($secret, 1111111111, 30, 8));
        self::assertSame('89005924', Totp::code($secret, 1234567890, 30, 8));
        self::assertSame('69279037', Totp::code($secret, 2000000000, 30, 8));
        self::assertSame('287082', Totp::code($secret, 59));

        self::assertSame(1, Totp::matchingStep($secret, '287082', 59));
        self::assertSame(1, Totp::matchingStep($secret, '287082', 89), 'one period of drift');
        self::assertNull(Totp::matchingStep($secret, '287082', 120));
        self::assertNull(Totp::matchingStep($secret, '28708', 59));
        self::assertNull(Totp::matchingStep($secret, 'abcdef', 59));
        self::assertSame(32, strlen(Totp::generateSecret()));
    }

    public function testSetupProducesAuthenticatorUriAndEncryptsTheSecret(): void
    {
        $auth = $this->totp();
        $setup = $auth->totp()->beginSetup($this->user);

        self::assertStringStartsWith('otpauth://totp/Acme:jane%40example.com?secret=' . $setup['secret'], $setup['uri']);
        self::assertStringContainsString('issuer=Acme', $setup['uri']);
        self::assertStringContainsString('digits=6&period=30', $setup['uri']);

        $stored = (string) $this->pdo->query('SELECT secret FROM auth_engine_mfa')->fetchColumn();
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString($setup['secret'], $stored);

        self::assertFalse($auth->totp()->isEnabled($this->user), 'not active until confirmed');
        self::assertFalse($auth->totp()->verify($this->user, Totp::code($setup['secret'], $this->now)));
        self::assertNull($auth->totp()->confirm($this->user, '000000'));
        self::assertTrue($auth->attempt('jane@example.com', 'password1')->ok(), 'unconfirmed setup does not gate login');
    }

    public function testConfirmVerifyReplayAndDisable(): void
    {
        $auth = $this->totp();
        [$secret, $recovery] = $this->enroll($auth);

        self::assertCount(8, $recovery);
        self::assertMatchesRegularExpression('/^[a-z2-7]{5}-[a-z2-7]{5}$/', $recovery[0]);
        self::assertTrue($auth->totp()->isEnabled($this->user));

        $code = Totp::code($secret, $this->now);
        self::assertTrue($auth->totp()->verify($this->user, substr($code, 0, 3) . ' ' . substr($code, 3)));
        self::assertFalse($auth->totp()->verify($this->user, $code), 'a code cannot be replayed');
        self::assertFalse($auth->totp()->verify($this->user, Totp::code($secret, $this->now - 30)), 'nor an older one');

        try {
            $auth->totp()->beginSetup($this->user);
            self::fail('Re-enrolling must require disabling first.');
        } catch (AuthEngineException) {
        }

        $auth->totp()->disable($this->user);
        self::assertFalse($auth->totp()->isEnabled($this->user));
    }

    public function testPasswordLoginIsParkedUntilTotpIsVerified(): void
    {
        $auth = $this->totp();
        [$secret] = $this->enroll($auth);

        $first = $auth->attempt('jane@example.com', 'password1');
        self::assertSame(AuthStatus::MfaRequired, $first->status);
        self::assertSame(['totp', 'recovery'], $first->mfaMethods);
        self::assertFalse($first->ok());
        self::assertTrue($auth->guest(), 'not logged in after the first factor');
        self::assertSame($this->user->getAuthIdentifier(), $auth->pendingMfa()['user_id'] ?? null);

        $wrong = $auth->verifyMfa('000000');
        self::assertSame('invalid_code', $wrong->reason);
        self::assertTrue($auth->guest());
        self::assertSame('invalid_method', $auth->verifyMfa('1', 'otp')->reason);

        // The pending step lives in the session, so it works on the next request too.
        $next = $this->totp();
        $before = $this->session->getId();
        self::assertTrue($next->verifyMfa(Totp::code($secret, $this->now))->ok());
        self::assertTrue($next->check());
        self::assertNotSame($before, $this->session->getId());
        self::assertNull($next->pendingMfa());
        self::assertSame('no_pending_challenge', $next->verifyMfa('123456')->reason);
    }

    public function testRecoveryCodeWorksOnceAndGuessingIsThrottled(): void
    {
        $auth = $this->totp();
        [, $recovery] = $this->enroll($auth);

        $auth->attempt('jane@example.com', 'password1');
        self::assertTrue($auth->verifyMfa(strtoupper($recovery[0]))->ok());
        self::assertSame(7, $auth->totp()->remainingRecoveryCodes($this->user));
        $auth->logout();

        $auth->attempt('jane@example.com', 'password1');
        self::assertFalse($auth->verifyMfa($recovery[0])->ok(), 'already spent');
        for ($i = 0; $i < 4; $i++) {
            $auth->verifyMfa('111111');
        }
        $blocked = $auth->verifyMfa($recovery[1]);
        self::assertSame(AuthStatus::RateLimited, $blocked->status);
        self::assertTrue($auth->guest());

        $this->now += 61;
        self::assertTrue($auth->verifyMfa($recovery[1], 'recovery')->ok());
    }

    public function testPendingChallengeExpiresAndLoginBypassClearsIt(): void
    {
        $auth = $this->totp(['mfa' => ['challenge_ttl' => 120]]);
        [$secret] = $this->enroll($auth);

        $auth->attempt('jane@example.com', 'password1');
        $this->now += 121;
        self::assertNull($auth->pendingMfa());
        self::assertSame('no_pending_challenge', $auth->verifyMfa(Totp::code($secret, $this->now))->reason);
    }

    public function testMagicLinkAlsoRequiresTheSecondFactor(): void
    {
        $auth = $this->totp(['drivers' => ['totp' => ['enabled' => true], 'magic_link' => ['enabled' => true]]]);
        [$secret] = $this->enroll($auth);

        $auth->sendMagicLink('jane@example.com');
        parse_str((string) parse_url((string) $this->mail->last()['data']['url'], PHP_URL_QUERY), $query);

        self::assertTrue($auth->loginWithMagicLink($query['token'])->requiresMfa());
        self::assertTrue($auth->verifyMfa(Totp::code($secret, $this->now), 'totp')->ok());
    }

    public function testEmailOtpAsSecondFactor(): void
    {
        $auth = $this->engine([
            'registration' => ['auto_login' => false],
            'drivers' => ['otp' => ['enabled' => true, 'second_factor' => true]],
        ]);
        $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);

        self::assertFalse($auth->sendMfaOtp(), 'nothing pending yet');
        $first = $auth->attempt('jane@example.com', 'password1');
        self::assertSame(['otp'], $first->mfaMethods);

        self::assertTrue($auth->sendMfaOtp());
        self::assertSame('jane@example.com', $this->mail->last()['to']);
        self::assertFalse($auth->verifyMfa('000000')->ok() && $this->mail->last()['data']['code'] !== '000000');
        self::assertTrue($auth->sendMfaOtp());
        self::assertTrue($auth->verifyMfa((string) $this->mail->last()['data']['code'])->ok());
        $auth->logout();

        $auth->sendOtp('jane@example.com');
        self::assertTrue(
            $auth->loginWithOtp('jane@example.com', (string) $this->mail->last()['data']['code'])->ok(),
            'an OTP login is not asked for a second OTP',
        );
    }
}
