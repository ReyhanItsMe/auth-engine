<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Adapters\Native;

use ReyhanItsMe\AuthEngine\Contracts\SessionInterface;

/**
 * In-memory session for CLI scripts, queue workers and tests.
 */
final class ArraySession implements SessionInterface
{
    private string $id;

    /** @param array<string, mixed> $data */
    public function __construct(private array $data = [])
    {
        $this->id = self::newId();
    }

    public function start(): void
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(bool $destroyOld = true): void
    {
        $this->id = self::newId();
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->id = self::newId();
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
