<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

use Closure;
use ReyhanItsMe\AuthEngine\Database\Connection;

/**
 * Single-use tokens (magic links) and short codes (OTP).
 * Only keyed hashes are stored, never the plain value.
 */
final class TokenStore
{
    /** @param Closure(): int $clock */
    public function __construct(
        private readonly Connection $db,
        private readonly Crypto $crypto,
        private readonly Closure $clock,
    ) {
    }

    /**
     * Issue a random URL-safe token for $subject. Earlier tokens of the same
     * type and subject are revoked.
     */
    public function issueToken(string $type, string $subject, int $ttl): string
    {
        $token = Crypto::randomToken();
        $this->store($type, $subject, $this->crypto->hmac($type . '|' . $token), $ttl);

        return $token;
    }

    /** @return string|null the subject the token was issued for; the token is destroyed */
    public function consumeToken(string $type, string $token): ?string
    {
        $table = $this->db->table('tokens');
        $hash = $this->crypto->hmac($type . '|' . $token);

        $row = $this->db->first("SELECT id, subject, expires_at FROM {$table} WHERE token_hash = ? AND type = ?", [$hash, $type]);
        if ($row === null) {
            return null;
        }

        // Delete first and trust only the winner, so a token can never be used twice.
        $deleted = $this->db->run("DELETE FROM {$table} WHERE id = ?", [$row['id']])->rowCount();

        return $deleted === 1 && (int) $row['expires_at'] > ($this->clock)() ? (string) $row['subject'] : null;
    }

    /** Issue a numeric one-time code of $digits digits for $subject. */
    public function issueCode(string $type, string $subject, int $digits, int $ttl): string
    {
        $code = str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
        $this->store($type, $subject, $this->crypto->hmac($type . '|' . $subject . '|' . $code), $ttl);

        return $code;
    }

    /**
     * Check a code. It is destroyed on success, on expiry, and after
     * $maxAttempts wrong guesses.
     */
    public function verifyCode(string $type, string $subject, string $code, int $maxAttempts = 5): bool
    {
        $table = $this->db->table('tokens');
        $row = $this->db->first("SELECT id, token_hash, attempts, expires_at FROM {$table} WHERE type = ? AND subject = ?", [$type, $subject]);

        if ($row === null) {
            return false;
        }

        if ((int) $row['expires_at'] <= ($this->clock)() || (int) $row['attempts'] >= $maxAttempts) {
            $this->db->run("DELETE FROM {$table} WHERE id = ?", [$row['id']]);

            return false;
        }

        if (!hash_equals((string) $row['token_hash'], $this->crypto->hmac($type . '|' . $subject . '|' . $code))) {
            $this->db->run("UPDATE {$table} SET attempts = attempts + 1 WHERE id = ?", [$row['id']]);

            return false;
        }

        return $this->db->run("DELETE FROM {$table} WHERE id = ?", [$row['id']])->rowCount() === 1;
    }

    public function revoke(string $type, string $subject): void
    {
        $this->db->run("DELETE FROM {$this->db->table('tokens')} WHERE type = ? AND subject = ?", [$type, $subject]);
    }

    public function purgeExpired(): int
    {
        return $this->db->run("DELETE FROM {$this->db->table('tokens')} WHERE expires_at <= ?", [($this->clock)()])->rowCount();
    }

    private function store(string $type, string $subject, string $hash, int $ttl): void
    {
        $now = ($this->clock)();
        $this->revoke($type, $subject);
        $this->db->run(
            "INSERT INTO {$this->db->table('tokens')} (type, subject, token_hash, attempts, expires_at, created_at) VALUES (?, ?, ?, 0, ?, ?)",
            [$type, $subject, $hash, $now + max(1, $ttl), $now],
        );
    }
}
