<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Methods\MagicLink;

use ReyhanItsMe\AuthEngine\Contracts\AuthMethodInterface;
use ReyhanItsMe\AuthEngine\Contracts\MutableUserProviderInterface;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\AuthResult;
use ReyhanItsMe\AuthEngine\Core\Identifier;
use ReyhanItsMe\AuthEngine\Exceptions\TooManyAttemptsException;

/**
 * Passwordless login through single-use links sent by e-mail.
 */
final class MagicLinkDriver implements AuthMethodInterface
{
    private const TYPE = 'magic_link';

    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function name(): string
    {
        return self::TYPE;
    }

    /**
     * E-mail a login link. Behaves the same whether or not the address has
     * an account, so it cannot be used to discover registered e-mails.
     *
     * @throws TooManyAttemptsException when links are requested too often
     */
    public function send(string $email): void
    {
        $email = Identifier::normalize('email', $email);
        if ($email === null) {
            return;
        }

        $throttle = 'magic|' . $email;
        if (($wait = $this->auth->throttled($throttle)) !== null) {
            throw new TooManyAttemptsException($wait);
        }
        $this->auth->hitThrottle($throttle);

        $users = $this->auth->users();
        $user = $users->findByIdentifier('email', $email);

        if (
            $user === null
            && $this->auth->config()->get('drivers.magic_link.auto_register') === true
            && $users instanceof MutableUserProviderInterface
        ) {
            $user = $users->create([
                'email' => $email,
                'name' => (string) strstr($email, '@', true),
                'password' => $this->auth->hasher()->unusable(),
            ]);
        }

        if ($user === null) {
            return;
        }

        $ttl = (int) $this->auth->config()->get('drivers.magic_link.ttl', 900);
        $token = $this->auth->tokens()->issueToken(self::TYPE, (string) $user->getAuthIdentifier(), $ttl);
        $url = $this->url($token);
        $app = (string) $this->auth->config()->get('app.name', 'Auth Engine');
        $minutes = max(1, intdiv($ttl, 60));

        $this->auth->notifier()->send(
            'email',
            $email,
            sprintf('Your sign-in link for %s', $app),
            sprintf(
                "Open this link to sign in to %s:\n\n%s\n\nThe link works once and expires in %d minute%s. "
                . "If you did not request it, you can ignore this e-mail.",
                $app,
                $url,
                $minutes,
                $minutes === 1 ? '' : 's',
            ),
            ['type' => self::TYPE, 'url' => $url, 'ttl' => $ttl, 'app' => $app],
        );
    }

    /** Exchange the token from the link for a login. Each token works once. */
    public function login(string $token): AuthResult
    {
        $userId = $token === '' || strlen($token) > 255 ? null : $this->auth->tokens()->consumeToken(self::TYPE, $token);
        $user = $userId === null ? null : $this->auth->users()->findById($userId);

        return $user === null
            ? AuthResult::failed('invalid_token')
            : $this->auth->authenticate($user, self::TYPE);
    }

    public function url(string $token): string
    {
        $route = '/' . ltrim((string) $this->auth->config()->get('drivers.magic_link.route', '/auth/magic'), '/');

        return $this->auth->adapter()->baseUrl() . $route . (str_contains($route, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
    }
}
