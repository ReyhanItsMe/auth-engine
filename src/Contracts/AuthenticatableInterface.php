<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Contracts;

interface AuthenticatableInterface
{
    /** Unique, stable identifier of the user (primary key). */
    public function getAuthIdentifier(): int|string;

    /** Stored password hash, or null for passwordless accounts. */
    public function getAuthPasswordHash(): ?string;
}
