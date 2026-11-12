<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

/**
 * Password hashing: Argon2id when available, Bcrypt otherwise.
 */
final class Hasher
{
    public function __construct(private readonly Config $config)
    {
    }

    public function algo(): string
    {
        return $this->config->get('hashing.algo') === 'argon2id' && defined('PASSWORD_ARGON2ID')
            ? 'argon2id'
            : 'bcrypt';
    }

    public function make(string $password): string
    {
        return password_hash($password, $this->constant(), $this->options());
    }

    /** Constant-time verification. A null/empty hash never matches. */
    public function verify(string $password, ?string $hash): bool
    {
        return $hash !== null && $hash !== '' && password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->constant(), $this->options());
    }

    /** A valid hash of a random value: an account that can never log in with a password. */
    public function unusable(): string
    {
        return $this->make(bin2hex(random_bytes(32)));
    }

    private function constant(): string
    {
        return $this->algo() === 'argon2id' ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string, int> */
    private function options(): array
    {
        if ($this->algo() === 'argon2id') {
            return [
                'memory_cost' => (int) $this->config->get('hashing.argon2id.memory_cost', 65536),
                'time_cost' => (int) $this->config->get('hashing.argon2id.time_cost', 4),
                'threads' => (int) $this->config->get('hashing.argon2id.threads', 1),
            ];
        }

        return ['cost' => (int) $this->config->get('hashing.bcrypt.cost', 12)];
    }
}
