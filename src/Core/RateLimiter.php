<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

use Closure;
use ReyhanItsMe\AuthEngine\Database\Connection;
use Throwable;

/**
 * Fixed-window attempt counter stored in the database.
 */
final class RateLimiter
{
    /** @param Closure(): int $clock */
    public function __construct(private readonly Connection $db, private readonly Closure $clock)
    {
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    public function attempts(string $key): int
    {
        $row = $this->row($key);

        return $row === null ? 0 : (int) $row['attempts'];
    }

    /** Seconds until the window of $key resets (0 when not limited). */
    public function availableIn(string $key): int
    {
        $row = $this->row($key);

        return $row === null ? 0 : max(0, (int) $row['expires_at'] - ($this->clock)());
    }

    /** Record one attempt; the window starts with the first attempt. */
    public function hit(string $key, int $decaySeconds): int
    {
        $table = $this->db->table('rate_limits');
        $bucket = self::bucket($key);
        $now = ($this->clock)();

        $updated = $this->db->run(
            "UPDATE {$table} SET attempts = attempts + 1 WHERE bucket = ? AND expires_at > ?",
            [$bucket, $now],
        )->rowCount();

        if ($updated === 0) {
            $this->db->run("DELETE FROM {$table} WHERE bucket = ? OR expires_at <= ?", [$bucket, $now]);

            try {
                $this->db->run(
                    "INSERT INTO {$table} (bucket, attempts, expires_at) VALUES (?, 1, ?)",
                    [$bucket, $now + max(1, $decaySeconds)],
                );
            } catch (Throwable) {
                // A concurrent request created the row first: count on top of it.
                $this->db->run("UPDATE {$table} SET attempts = attempts + 1 WHERE bucket = ?", [$bucket]);
            }
        }

        return $this->attempts($key);
    }

    public function clear(string $key): void
    {
        $this->db->run("DELETE FROM {$this->db->table('rate_limits')} WHERE bucket = ?", [self::bucket($key)]);
    }

    /** @return array<string, mixed>|null */
    private function row(string $key): ?array
    {
        return $this->db->first(
            "SELECT attempts, expires_at FROM {$this->db->table('rate_limits')} WHERE bucket = ? AND expires_at > ?",
            [self::bucket($key), ($this->clock)()],
        );
    }

    private static function bucket(string $key): string
    {
        return hash('sha256', $key);
    }
}
