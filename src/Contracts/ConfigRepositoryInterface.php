<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Contracts;

interface ConfigRepositoryInterface
{
    /** Read a value using dot notation, e.g. "drivers.totp.enabled". */
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    /** @return array<string, mixed> */
    public function all(): array;
}
