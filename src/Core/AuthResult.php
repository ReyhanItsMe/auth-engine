<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;

/**
 * Outcome of an authentication attempt.
 *
 *   $result = Auth::attempt($email, $password);
 *   if ($result->ok()) { ... } elseif ($result->requiresMfa()) { ... }
 */
final class AuthResult
{
    /** @param list<string> $mfaMethods */
    private function __construct(
        public readonly AuthStatus $status,
        public readonly ?AuthenticatableInterface $user = null,
        public readonly ?string $reason = null,
        public readonly int $retryAfter = 0,
        public readonly array $mfaMethods = [],
    ) {
    }

    public static function success(AuthenticatableInterface $user): self
    {
        return new self(AuthStatus::Success, $user);
    }

    /** @param string $reason machine-readable, e.g. invalid_credentials, invalid_token, invalid_code */
    public static function failed(string $reason): self
    {
        return new self(AuthStatus::Failed, null, $reason);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(AuthStatus::RateLimited, null, 'rate_limited', $retryAfter);
    }

    /** @param list<string> $methods second factors the user may use: totp, recovery, otp */
    public static function mfaRequired(array $methods): self
    {
        return new self(AuthStatus::MfaRequired, null, 'mfa_required', 0, $methods);
    }

    /** True only when the user is now fully logged in. */
    public function ok(): bool
    {
        return $this->status === AuthStatus::Success;
    }

    public function requiresMfa(): bool
    {
        return $this->status === AuthStatus::MfaRequired;
    }

    /** @return array<string, mixed> safe to return as JSON */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason,
            'retry_after' => $this->retryAfter,
            'mfa_methods' => $this->mfaMethods,
            'user_id' => $this->user?->getAuthIdentifier(),
        ];
    }
}
