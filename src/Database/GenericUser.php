<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Database;

use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Contracts\NotifiableInterface;

/**
 * Plain user record returned by the built-in PdoUserProvider.
 */
final class GenericUser implements AuthenticatableInterface, NotifiableInterface
{
    /** @param array<string, mixed> $attributes one row of the users table */
    public function __construct(private readonly array $attributes)
    {
    }

    public function getAuthIdentifier(): int|string
    {
        $id = $this->attributes['id'];

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : (string) $id;
    }

    public function getAuthPasswordHash(): ?string
    {
        $hash = $this->attributes['password'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function routeNotificationFor(string $channel): ?string
    {
        $value = $this->attributes[$channel === 'sms' ? 'phone_number' : 'email'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $key === 'password' ? $default : ($this->attributes[$key] ?? $default);
    }

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __isset(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /** @return array<string, mixed> every column except the password hash */
    public function toArray(): array
    {
        return array_diff_key($this->attributes, ['password' => true]);
    }
}
