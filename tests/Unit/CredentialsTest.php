<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Unit;

use ReyhanItsMe\AuthEngine\Core\AuthStatus;
use ReyhanItsMe\AuthEngine\Core\Crypto;
use ReyhanItsMe\AuthEngine\Core\Hasher;
use ReyhanItsMe\AuthEngine\Core\Identifier;
use ReyhanItsMe\AuthEngine\Database\GenericUser;
use ReyhanItsMe\AuthEngine\Exceptions\AuthEngineException;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;
use ReyhanItsMe\AuthEngine\Exceptions\ValidationException;
use ReyhanItsMe\AuthEngine\Tests\Support\EngineTestCase;

final class CredentialsTest extends EngineTestCase
{
    private const ALL = ['identifiers' => ['email', 'username', 'phone_number']];

    public function testRegisterCreatesHashedUserAndLogsIn(): void
    {
        $auth = $this->engine();
        $user = $auth->register(['email' => ' Jane@Example.COM ', 'password' => 'correct horse', 'name' => 'Jane']);

        self::assertInstanceOf(GenericUser::class, $user);
        self::assertSame('jane@example.com', $user->get('email'));
        self::assertSame('Jane', $user->get('name'));
        self::assertNull($user->get('password'), 'the hash is never exposed through get()');
        self::assertArrayNotHasKey('password', $user->toArray());
        self::assertNotSame('correct horse', $user->getAuthPasswordHash());
        self::assertTrue(password_verify('correct horse', (string) $user->getAuthPasswordHash()));
        self::assertTrue($auth->check(), 'registration.auto_login defaults to true');
        self::assertSame($user->getAuthIdentifier(), $auth->id());
    }

    public function testRegisterWithoutAutoLogin(): void
    {
        $auth = $this->engine(['registration' => ['auto_login' => false]]);
        $auth->register(['email' => 'a@b.co', 'password' => 'password1']);

        self::assertTrue($auth->guest());
    }

    public function testRegisterValidation(): void
    {
        $auth = $this->engine(self::ALL);
        $auth->register(['email' => 'taken@example.com', 'username' => 'taken', 'password' => 'password1']);

        $cases = [
            [['password' => 'password1'], ['email']],
            [['email' => 'nope', 'password' => 'password1'], ['email']],
            [['email' => 'taken@example.com', 'password' => 'password1'], ['email']],
            [['username' => 'TAKEN', 'password' => 'password1'], ['username']],
            [['username' => 'a b', 'password' => 'password1'], ['username']],
            [['phone_number' => '12', 'password' => 'password1'], ['phone_number']],
            [['email' => 'ok@example.com', 'password' => 'short'], ['password']],
            [['email' => 'ok@example.com'], ['password']],
            [['email' => 'bad', 'password' => ''], ['email', 'password']],
        ];

        foreach ($cases as [$data, $fields]) {
            try {
                $auth->credentials()->register($data);
                self::fail('Expected ValidationException for ' . json_encode($data));
            } catch (ValidationException $e) {
                self::assertSame($fields, array_keys($e->errors()), json_encode($data));
            }
        }
    }

    public function testAttemptWithEveryIdentifier(): void
    {
        $auth = $this->engine(self::ALL + ['registration' => ['auto_login' => false]]);
        $user = $auth->register([
            'email' => 'jane@example.com',
            'username' => 'Jane_Doe',
            'phone_number' => '+62 812-3456-7890',
            'password' => 'password1',
        ]);

        foreach (['JANE@example.com', 'jane_doe', '+6281234567890', ' +62 812 3456 7890 '] as $identifier) {
            $result = $auth->attempt($identifier, 'password1');
            self::assertTrue($result->ok(), $identifier);
            self::assertSame($user->getAuthIdentifier(), $result->user?->getAuthIdentifier());
            self::assertSame('success', $result->toArray()['status']);
            $auth->logout();
        }
    }

    public function testIdentifiersOutsideConfigCannotBeUsed(): void
    {
        $auth = $this->engine(self::ALL + ['registration' => ['auto_login' => false]]);
        $auth->register(['email' => 'jane@example.com', 'username' => 'jane', 'password' => 'password1']);

        $emailOnly = $this->engine();
        self::assertFalse($emailOnly->attempt('jane', 'password1')->ok());
        self::assertTrue($emailOnly->attempt('jane@example.com', 'password1')->ok());
    }

    public function testWrongPasswordAndUnknownUserLookIdentical(): void
    {
        $auth = $this->engine(['registration' => ['auto_login' => false]]);
        $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);

        $wrong = $auth->attempt('jane@example.com', 'password2');
        $unknown = $auth->attempt('ghost@example.com', 'password1');

        self::assertSame(AuthStatus::Failed, $wrong->status);
        self::assertSame($wrong->toArray(), $unknown->toArray());
        self::assertSame('invalid_credentials', $wrong->reason);
        self::assertTrue($auth->guest());
    }

    public function testSqlInjectionAttemptIsJustAWrongIdentifier(): void
    {
        $auth = $this->engine(self::ALL + ['registration' => ['auto_login' => false]]);
        $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);

        self::assertFalse($auth->attempt("' OR 1=1 --", 'x')->ok());
        self::assertFalse($auth->attempt("jane@example.com' --", 'password1')->ok());
        self::assertNull($auth->users()->findByIdentifier('id = id OR email', 'x'), 'unknown columns are refused');
    }

    public function testBruteForceIsRateLimitedPerIdentifierAndRecovers(): void
    {
        $auth = $this->engine(['registration' => ['auto_login' => false]]);
        $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);
        $auth->register(['email' => 'john@example.com', 'password' => 'password1']);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(AuthStatus::Failed, $auth->attempt('jane@example.com', 'wrong')->status);
        }

        $blocked = $auth->attempt('jane@example.com', 'password1');
        self::assertSame(AuthStatus::RateLimited, $blocked->status, 'even the right password is refused while locked');
        self::assertSame(60, $blocked->retryAfter);
        self::assertTrue($auth->attempt('john@example.com', 'password1')->ok(), 'other accounts are unaffected');
        $auth->logout();

        $this->now += 61;
        self::assertTrue($auth->attempt('jane@example.com', 'password1')->ok());
    }

    public function testSuccessfulLoginResetsTheCounterAndLimiterCanBeDisabled(): void
    {
        $auth = $this->engine(['registration' => ['auto_login' => false]]);
        $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);

        for ($round = 0; $round < 3; $round++) {
            for ($i = 0; $i < 4; $i++) {
                $auth->attempt('jane@example.com', 'wrong');
            }
            self::assertTrue($auth->attempt('jane@example.com', 'password1')->ok());
            $auth->logout();
        }

        $open = $this->engine(['rate_limit' => ['enabled' => false]]);
        for ($i = 0; $i < 8; $i++) {
            self::assertSame(AuthStatus::Failed, $open->attempt('jane@example.com', 'wrong')->status);
        }
    }

    public function testPasswordIsRehashedWhenTheCostChanges(): void
    {
        $auth = $this->engine(['registration' => ['auto_login' => false]]);
        $user = $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);
        $old = $user->getAuthPasswordHash();

        $stronger = $this->engine(['hashing' => ['bcrypt' => ['cost' => 5]]]);
        self::assertTrue($stronger->attempt('jane@example.com', 'password1')->ok());

        $new = $stronger->users()->findById($user->getAuthIdentifier())?->getAuthPasswordHash();
        self::assertNotSame($old, $new);
        self::assertStringStartsWith('$2y$05$', (string) $new);
    }

    public function testSetPasswordAndSessionSurvivesAcrossRequests(): void
    {
        $auth = $this->engine();
        $user = $auth->register(['email' => 'jane@example.com', 'password' => 'password1']);
        $auth->credentials()->setPassword($user, 'new-password');

        $next = $this->engine();
        self::assertSame('jane@example.com', $next->user()?->get('email'), 'restored through the default PDO provider');
        $next->logout();
        self::assertFalse($next->attempt('jane@example.com', 'password1')->ok());
        self::assertTrue($next->attempt('jane@example.com', 'new-password')->ok());
    }

    public function testHasherAndCryptoPrimitives(): void
    {
        $auth = $this->engine();
        $argon = new Hasher($auth->config()->with('hashing.algo', 'argon2id')->with('hashing.argon2id', ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]));

        if (defined('PASSWORD_ARGON2ID')) {
            self::assertSame('argon2id', $argon->algo());
            self::assertStringStartsWith('$argon2id$', $argon->make('secret'));
        }
        self::assertTrue($argon->verify('secret', $argon->make('secret')));
        self::assertFalse($argon->verify('secret', null));
        self::assertFalse($argon->verify('secret', ''));
        self::assertFalse($argon->verify('anything', $argon->unusable()));

        $crypto = $auth->crypto();
        $box = $crypto->encrypt('totp-secret');
        self::assertStringNotContainsString('totp-secret', $box);
        self::assertNotSame($box, $crypto->encrypt('totp-secret'), 'random IV');
        self::assertSame('totp-secret', $crypto->decrypt($box));
        self::assertSame("\x00\xff binary", Crypto::base64UrlDecode(Crypto::base64UrlEncode("\x00\xff binary")));
        self::assertNull(Crypto::base64UrlDecode('not base64!'));

        try {
            $crypto->decrypt(substr($box, 0, -4) . 'AAAA');
            self::fail('Tampered ciphertext must not decrypt.');
        } catch (AuthEngineException) {
        }

        $this->expectException(ConfigurationException::class);
        (new Crypto('short'))->hmac('x');
    }

    public function testIdentifierNormalisation(): void
    {
        self::assertSame('a@b.co', Identifier::normalize('email', ' A@B.co '));
        self::assertNull(Identifier::normalize('email', 'a@b'));
        self::assertSame('jane.doe', Identifier::normalize('username', 'Jane.Doe'));
        self::assertNull(Identifier::normalize('username', '-jane'));
        self::assertSame('+628123456789', Identifier::normalize('phone_number', '+62 (812) 345-6789'));
        self::assertNull(Identifier::normalize('phone_number', '0812'));
        self::assertNull(Identifier::normalize('id', '1'));
        self::assertSame(['username' => '08123456789'], Identifier::candidates('08123456789', ['email', 'username', 'phone_number']));
        self::assertSame(['email' => 'a@b.co'], Identifier::candidates('a@b.co', ['username', 'email']));
    }

    public function testSchemaInstallIsIdempotentAndTableNamesAreValidated(): void
    {
        $auth = $this->engine();
        $auth->installSchema();
        $auth->installSchema();
        self::assertSame(0, $auth->limiter()->attempts('anything'));

        $this->expectException(ConfigurationException::class);
        $this->engine(['database' => ['tables' => ['mfa' => 'mfa; DROP TABLE users']]]);
    }
}
