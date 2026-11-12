<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Contracts;

/**
 * A user provider that can also create accounts and change passwords.
 * Required by registration, magic-link auto-registration and OAuth sign-up.
 */
interface MutableUserProviderInterface extends UserProviderInterface
{
    /**
     * @param array<string, mixed> $attributes e.g. email, username, phone_number, name, password (already hashed)
     */
    public function create(array $attributes): AuthenticatableInterface;

    public function updatePasswordHash(int|string $id, string $hash): void;
}
