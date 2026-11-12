<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Methods\Totp;

use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Contracts\AuthMethodInterface;
use ReyhanItsMe\AuthEngine\Contracts\NotifiableInterface;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Exceptions\AuthEngineException;

/**
 * Authenticator-app second factor with single-use recovery codes.
 * Secrets are encrypted at rest with "app.key".
 */
final class TotpDriver implements AuthMethodInterface
{
    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function name(): string
    {
        return 'totp';
    }

    /**
     * Start enrolment: creates an unconfirmed secret.
     * Show "uri" as a QR code (or "secret" for manual entry), then call confirm().
     *
     * @return array{secret: string, uri: string}
     */
    public function beginSetup(AuthenticatableInterface $user, ?string $accountLabel = null): array
    {
        if ($this->isEnabled($user)) {
            throw new AuthEngineException('TOTP is already enabled for this user. Disable it first.');
        }

        $db = $this->auth->db();
        $id = (string) $user->getAuthIdentifier();
        $secret = Totp::generateSecret();

        $db->run("DELETE FROM {$db->table('mfa')} WHERE user_id = ?", [$id]);
        $db->insert($db->table('mfa'), [
            'user_id' => $id,
            'secret' => $this->auth->crypto()->encrypt($secret),
            'last_step' => 0,
            'created_at' => $this->auth->now(),
        ]);

        $label = $accountLabel
            ?? ($user instanceof NotifiableInterface ? $user->routeNotificationFor('email') : null)
            ?? $id;

        return ['secret' => $secret, 'uri' => Totp::uri($this->issuer(), $label, $secret, $this->digits(), $this->period())];
    }

    /**
     * Finish enrolment with the first code from the app.
     *
     * @return list<string>|null recovery codes (shown once), or null when the code is wrong
     */
    public function confirm(AuthenticatableInterface $user, string $code): ?array
    {
        $row = $this->row($user);
        if ($row === null || $row['confirmed_at'] !== null) {
            return null;
        }

        $step = $this->match($row, $code);
        if ($step === null) {
            return null;
        }

        $db = $this->auth->db();
        $db->run(
            "UPDATE {$db->table('mfa')} SET confirmed_at = ?, last_step = ? WHERE user_id = ?",
            [$this->auth->now(), $step, (string) $user->getAuthIdentifier()],
        );

        return $this->regenerateRecoveryCodes($user);
    }

    public function isEnabled(AuthenticatableInterface $user): bool
    {
        $row = $this->row($user);

        return $row !== null && $row['confirmed_at'] !== null;
    }

    /** Verify a code. Each code is accepted once (replay protection). */
    public function verify(AuthenticatableInterface $user, string $code): bool
    {
        $row = $this->row($user);
        if ($row === null || $row['confirmed_at'] === null) {
            return false;
        }

        $step = $this->match($row, $code);
        if ($step === null) {
            return false;
        }

        $db = $this->auth->db();

        return $db->run(
            "UPDATE {$db->table('mfa')} SET last_step = ? WHERE user_id = ? AND last_step < ?",
            [$step, (string) $user->getAuthIdentifier(), $step],
        )->rowCount() === 1;
    }

    /** Spend one recovery code. */
    public function useRecoveryCode(AuthenticatableInterface $user, string $code): bool
    {
        $row = $this->row($user);
        $code = strtolower(trim($code));
        if ($row === null || $row['confirmed_at'] === null || preg_match('/^[a-z2-7]{5}-[a-z2-7]{5}$/', $code) !== 1) {
            return false;
        }

        $hashes = json_decode((string) $row['recovery_codes'], true);
        $hashes = is_array($hashes) ? $hashes : [];
        $hash = $this->auth->crypto()->hmac('recovery|' . $code);
        $remaining = array_values(array_filter($hashes, static fn (mixed $h): bool => !is_string($h) || !hash_equals($h, $hash)));

        if (count($remaining) === count($hashes)) {
            return false;
        }

        $db = $this->auth->db();

        // Guard on the old value so two concurrent requests cannot spend the same code.
        return $db->run(
            "UPDATE {$db->table('mfa')} SET recovery_codes = ? WHERE user_id = ? AND recovery_codes = ?",
            [json_encode($remaining), (string) $user->getAuthIdentifier(), (string) $row['recovery_codes']],
        )->rowCount() === 1;
    }

    /**
     * Replace all recovery codes.
     *
     * @return list<string> the new codes; only their hashes are stored
     */
    public function regenerateRecoveryCodes(AuthenticatableInterface $user): array
    {
        $count = max(1, (int) $this->auth->config()->get('drivers.totp.recovery_codes', 8));
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtolower(Base32::encode(random_bytes(7)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        $crypto = $this->auth->crypto();
        $db = $this->auth->db();
        $db->run(
            "UPDATE {$db->table('mfa')} SET recovery_codes = ? WHERE user_id = ?",
            [json_encode(array_map(static fn (string $c): string => $crypto->hmac('recovery|' . $c), $codes)), (string) $user->getAuthIdentifier()],
        );

        return $codes;
    }

    public function remainingRecoveryCodes(AuthenticatableInterface $user): int
    {
        $hashes = json_decode((string) ($this->row($user)['recovery_codes'] ?? '[]'), true);

        return is_array($hashes) ? count($hashes) : 0;
    }

    public function disable(AuthenticatableInterface $user): void
    {
        $db = $this->auth->db();
        $db->run("DELETE FROM {$db->table('mfa')} WHERE user_id = ?", [(string) $user->getAuthIdentifier()]);
    }

    /** @return array<string, mixed>|null */
    private function row(AuthenticatableInterface $user): ?array
    {
        $db = $this->auth->db();

        return $db->first("SELECT * FROM {$db->table('mfa')} WHERE user_id = ?", [(string) $user->getAuthIdentifier()]);
    }

    /** @param array<string, mixed> $row */
    private function match(array $row, string $code): ?int
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        $secret = $this->auth->crypto()->decrypt((string) $row['secret']);
        $step = Totp::matchingStep(
            $secret,
            $code,
            $this->auth->now(),
            $this->period(),
            $this->digits(),
            max(0, (int) $this->auth->config()->get('drivers.totp.window', 1)),
        );

        return $step !== null && $step > (int) $row['last_step'] ? $step : null;
    }

    private function issuer(): string
    {
        $issuer = $this->auth->config()->get('drivers.totp.issuer');

        return is_string($issuer) && $issuer !== '' ? $issuer : (string) $this->auth->config()->get('app.name', 'Auth Engine');
    }

    private function digits(): int
    {
        return (int) $this->auth->config()->get('drivers.totp.digits', 6);
    }

    private function period(): int
    {
        return max(1, (int) $this->auth->config()->get('drivers.totp.period', 30));
    }
}
