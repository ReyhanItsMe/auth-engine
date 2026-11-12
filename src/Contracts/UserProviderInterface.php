<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Contracts;

interface UserProviderInterface
{
    public function findById(int|string $id): ?AuthenticatableInterface;

    /**
     * @param string $field one of the configured identifiers (email, username, phone_number)
     */
    public function findByIdentifier(string $field, string $value): ?AuthenticatableInterface;
}
