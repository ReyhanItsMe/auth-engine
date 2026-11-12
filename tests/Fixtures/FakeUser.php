<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Fixtures;

use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;

final class FakeUser implements AuthenticatableInterface
{
    public function __construct(public readonly int|string $id, public readonly ?string $hash = null)
    {
    }

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPasswordHash(): ?string
    {
        return $this->hash;
    }
}
