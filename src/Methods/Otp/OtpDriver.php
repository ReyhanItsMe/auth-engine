<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Methods\Otp;

use ReyhanItsMe\AuthEngine\Contracts\AuthMethodInterface;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\AuthResult;
use ReyhanItsMe\AuthEngine\Core\Identifier;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;
use ReyhanItsMe\AuthEngine\Exceptions\TooManyAttemptsException;
use ReyhanItsMe\AuthEngine\Exceptions\ValidationException;

/**
 * One-time numeric codes delivered by e-mail or SMS.
 */
final class OtpDriver implements AuthMethodInterface
{
    private const TYPE = 'otp';

    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function name(): string
    {
        return self::TYPE;
    }

    /**
     * Send a fresh code; any earlier code for the recipient stops working.
     *
     * @throws TooManyAttemptsException when codes are requested too often
     */
    public function send(string $recipient, string $channel = 'email'): void
    {
        $recipient = $this->recipient($recipient, $channel);

        $throttle = 'otp-send|' . $channel . '|' . $recipient;
        if (($wait = $this->auth->throttled($throttle)) !== null) {
            throw new TooManyAttemptsException($wait);
        }
        $this->auth->hitThrottle($throttle);

        $config = $this->auth->config();
        $ttl = (int) $config->get('drivers.otp.ttl', 300);
        $code = $this->auth->tokens()->issueCode(self::TYPE, $channel . ':' . $recipient, (int) $config->get('drivers.otp.digits', 6), $ttl);
        $app = (string) $config->get('app.name', 'Auth Engine');
        $minutes = max(1, intdiv($ttl, 60));

        $this->auth->notifier()->send(
            $channel,
            $recipient,
            sprintf('%s is your %s verification code', $code, $app),
            sprintf('Your %s verification code is %s. It expires in %d minute%s. Never share this code.', $app, $code, $minutes, $minutes === 1 ? '' : 's'),
            ['type' => self::TYPE, 'code' => $code, 'ttl' => $ttl, 'app' => $app],
        );
    }

    /** True once per code; the code dies after too many wrong guesses. */
    public function verify(string $recipient, string $code, string $channel = 'email'): bool
    {
        try {
            $recipient = $this->recipient($recipient, $channel);
        } catch (ValidationException) {
            return false;
        }

        $code = trim($code);
        if (preg_match('/^\d{4,8}$/', $code) !== 1) {
            return false;
        }

        return $this->auth->tokens()->verifyCode(
            self::TYPE,
            $channel . ':' . $recipient,
            $code,
            (int) $this->auth->config()->get('drivers.otp.max_attempts', 5),
        );
    }

    /** Passwordless login: verify the code, then log in the account that owns the address. */
    public function login(string $recipient, string $code, string $channel = 'email'): AuthResult
    {
        if (!$this->verify($recipient, $code, $channel)) {
            return AuthResult::failed('invalid_code');
        }

        $field = $channel === 'sms' ? 'phone_number' : 'email';
        $user = $this->auth->users()->findByIdentifier($field, $this->recipient($recipient, $channel));

        return $user === null
            ? AuthResult::failed('invalid_code')
            : $this->auth->authenticate($user, self::TYPE);
    }

    private function recipient(string $recipient, string $channel): string
    {
        if (!in_array($channel, (array) $this->auth->config()->get('drivers.otp.channels', ['email']), true)) {
            throw new ConfigurationException(sprintf('OTP channel "%s" is not enabled in "drivers.otp.channels".', $channel));
        }

        $normalized = Identifier::normalize($channel === 'sms' ? 'phone_number' : 'email', $recipient);
        if ($normalized === null) {
            throw new ValidationException(['recipient' => 'The recipient is not valid.']);
        }

        return $normalized;
    }
}
