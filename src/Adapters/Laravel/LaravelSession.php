<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Adapters\Laravel;

use ReyhanItsMe\AuthEngine\Contracts\SessionInterface;

/**
 * Wraps Laravel's session store (Illuminate\Contracts\Session\Session).
 * Typed as object so the core never hard-depends on illuminate/*.
 */
final class LaravelSession implements SessionInterface
{
    public function __construct(private readonly object $store)
    {
    }

    public function start(): void
    {
        if (!$this->store->isStarted()) {
            $this->store->start();
        }
    }

    public function getId(): string
    {
        return (string) $this->store->getId();
    }

    public function has(string $key): bool
    {
        return (bool) $this->store->exists($key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($key, $default);
    }

    public function put(string $key, mixed $value): void
    {
        $this->store->put($key, $value);
    }

    public function forget(string $key): void
    {
        $this->store->forget($key);
    }

    public function regenerate(bool $destroyOld = true): void
    {
        $this->store->migrate($destroyOld);
    }

    public function invalidate(): void
    {
        $this->store->invalidate();
    }
}
