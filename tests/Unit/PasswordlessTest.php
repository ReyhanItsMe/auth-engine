<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Unit;

use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;
use ReyhanItsMe\AuthEngine\Exceptions\DriverNotEnabledException;
use ReyhanItsMe\AuthEngine\Exceptions\TooManyAttemptsException;
use ReyhanItsMe\AuthEngine\Exceptions\ValidationException;
use ReyhanItsMe\AuthEngine\Tests\Support\EngineTestCase;

final class PasswordlessTest extends EngineTestCase
{
    /** @param array<string, mixed> $driver */
    private function magic(array $driver = []): AuthManager
    {
        $auth = $this->engine([
            'registration' => ['auto_login' => false],
            'drivers' => ['magic_link' => ['enabled' => true] + $driver],
        ]);
        if ($auth->users()->findByIdentifier('email', 'jane@example.com') === null) {
            $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);
        }

        return $auth;
    }

    private function tokenFromMail(): string
    {
        $url = (string) $this->mail->last()['data']['url'];
        self::assertStringStartsWith('https://app.test/auth/magic?token=', $url);
        self::assertStringContainsString($url, $this->mail->last()['text']);

        return rawurldecode(substr($url, strlen('https://app.test/auth/magic?token=')));
    }

    public function testMagicLinkLogsInExactlyOnce(): void
    {
        $auth = $this->magic();
        $auth->sendMagicLink('Jane@Example.com');

        self::assertCount(1, $this->mail->sent);
        self::assertSame('jane@example.com', $this->mail->last()['to']);
        self::assertSame('email', $this->mail->last()['channel']);
        $token = $this->tokenFromMail();

        $stored = $this->pdo->query('SELECT token_hash FROM auth_engine_tokens')->fetchColumn();
        self::assertNotSame($token, $stored, 'only a keyed hash is stored');

        self::assertTrue($auth->loginWithMagicLink($token)->ok());
        self::assertTrue($auth->check());
        $auth->logout();

        $again = $auth->loginWithMagicLink($token);
        self::assertFalse($again->ok(), 'single use');
        self::assertSame('invalid_token', $again->reason);
    }

    public function testMagicLinkExpiresAndRejectsGarbage(): void
    {
        $auth = $this->magic(['ttl' => 60]);
        $auth->sendMagicLink('jane@example.com');
        $token = $this->tokenFromMail();

        self::assertFalse($auth->loginWithMagicLink('')->ok());
        self::assertFalse($auth->loginWithMagicLink($token . 'x')->ok());

        $this->now += 61;
        self::assertFalse($auth->loginWithMagicLink($token)->ok());
        self::assertTrue($auth->guest());
    }

    public function testNewLinkRevokesTheOldOne(): void
    {
        $auth = $this->magic();
        $auth->sendMagicLink('jane@example.com');
        $first = $this->tokenFromMail();
        $auth->sendMagicLink('jane@example.com');
        $second = $this->tokenFromMail();

        self::assertFalse($auth->loginWithMagicLink($first)->ok());
        self::assertTrue($auth->loginWithMagicLink($second)->ok());
    }

    public function testUnknownAddressGetsNoMailUnlessAutoRegisterIsOn(): void
    {
        $auth = $this->magic();
        $auth->sendMagicLink('ghost@example.com');
        $auth->sendMagicLink('not-an-email');
        self::assertCount(0, $this->mail->sent, 'no mail and no error: addresses cannot be enumerated');

        $open = $this->magic(['auto_register' => true]);
        $open->sendMagicLink('new@example.com');
        self::assertCount(1, $this->mail->sent);

        $result = $open->loginWithMagicLink($this->tokenFromMail());
        self::assertTrue($result->ok());
        self::assertSame('new@example.com', $open->user()?->get('email'));
        self::assertFalse($open->attempt('new@example.com', '')->ok(), 'auto-registered accounts have no usable password');
    }

    public function testRequestingLinksIsThrottled(): void
    {
        $auth = $this->magic();
        for ($i = 0; $i < 5; $i++) {
            $auth->sendMagicLink('jane@example.com');
        }

        try {
            $auth->sendMagicLink('jane@example.com');
            self::fail('Expected TooManyAttemptsException.');
        } catch (TooManyAttemptsException $e) {
            self::assertSame(60, $e->retryAfter());
        }
        self::assertCount(5, $this->mail->sent);
    }

    public function testDisabledDriversAreRefusedAndNeverBuilt(): void
    {
        $auth = $this->engine();

        foreach (['sendMagicLink' => ['a@b.co'], 'sendOtp' => ['a@b.co'], 'loginWithMagicLink' => ['x']] as $method => $args) {
            try {
                $auth->{$method}(...$args);
                self::fail("$method should need its driver.");
            } catch (DriverNotEnabledException) {
            }
        }

        self::assertSame([], $auth->getActiveProviders());
        foreach (['magic_link', 'otp', 'totp', 'webauthn', 'oauth'] as $driver) {
            self::assertFalse($auth->drivers()->isResolved($driver), $driver);
        }
    }

    /** @param array<string, mixed> $driver */
    private function otp(array $driver = []): AuthManager
    {
        return $this->engine([
            'identifiers' => ['email', 'phone_number'],
            'registration' => ['auto_login' => false],
            'drivers' => ['otp' => ['enabled' => true] + $driver],
        ]);
    }

    public function testOtpVerifiesOnceAndIsStoredHashed(): void
    {
        $auth = $this->otp();
        $auth->sendOtp('Jane@Example.com');

        $code = (string) $this->mail->last()['data']['code'];
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertStringContainsString($code, $this->mail->last()['text']);
        self::assertStringNotContainsString($code, (string) $this->pdo->query('SELECT token_hash FROM auth_engine_tokens')->fetchColumn());

        self::assertFalse($auth->verifyOtp('other@example.com', $code), 'bound to the recipient');
        self::assertTrue($auth->verifyOtp('jane@example.com', " $code "));
        self::assertFalse($auth->verifyOtp('jane@example.com', $code), 'single use');
    }

    public function testOtpDiesAfterTooManyWrongGuessesOrExpiry(): void
    {
        $auth = $this->otp(['digits' => 4, 'max_attempts' => 3, 'ttl' => 120]);
        $auth->sendOtp('jane@example.com');
        $code = (string) $this->mail->last()['data']['code'];
        self::assertSame(4, strlen($code));
        $wrong = $code === '0000' ? '0001' : '0000';

        for ($i = 0; $i < 3; $i++) {
            self::assertFalse($auth->verifyOtp('jane@example.com', $wrong));
        }
        self::assertFalse($auth->verifyOtp('jane@example.com', $code), 'burned after 3 wrong guesses');

        $auth->sendOtp('jane@example.com');
        $fresh = (string) $this->mail->last()['data']['code'];
        $this->now += 121;
        self::assertFalse($auth->verifyOtp('jane@example.com', $fresh), 'expired');
    }

    public function testOtpLoginByEmailAndSmsThroughCustomSender(): void
    {
        $auth = $this->otp(['channels' => ['email', 'sms']]);
        $auth->register(['email' => 'jane@example.com', 'phone_number' => '+6281234567890', 'password' => 'password1']);

        $auth->sendOtp('jane@example.com');
        self::assertTrue($auth->loginWithOtp('jane@example.com', (string) $this->mail->last()['data']['code'])->ok());
        $auth->logout();

        $sms = [];
        $auth->setNotifier(function (string $channel, string $to, string $subject, string $text, array $data) use (&$sms): void {
            $sms[] = [$channel, $to, $data['code']];
        });
        $auth->sendOtp('+62 812-3456-7890', 'sms');
        self::assertSame(['sms', '+6281234567890'], [$sms[0][0], $sms[0][1]]);
        self::assertTrue($auth->loginWithOtp('+6281234567890', $sms[0][2], 'sms')->ok());
    }

    public function testOtpForUnknownAccountVerifiesButCannotLogIn(): void
    {
        $auth = $this->otp();
        $auth->sendOtp('stranger@example.com');
        $result = $auth->loginWithOtp('stranger@example.com', (string) $this->mail->last()['data']['code']);

        self::assertSame('invalid_code', $result->reason);
        self::assertTrue($auth->guest());
    }

    public function testOtpChannelAndRecipientAreValidated(): void
    {
        $auth = $this->otp();

        try {
            $auth->sendOtp('+6281234567890', 'sms');
            self::fail('sms is not enabled.');
        } catch (ConfigurationException) {
        }

        $this->expectException(ValidationException::class);
        $auth->sendOtp('nope');
    }
}
