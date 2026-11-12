<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

use Closure;
use ReyhanItsMe\AuthEngine\Contracts\AuthMethodInterface;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;

/**
 * Lazy registry of authentication drivers.
 * Factories are stored cheaply; a driver object is only built on first use.
 */
final class DriverRegistry
{
    /** @var array<string, Closure(AuthManager): AuthMethodInterface> */
    private array $factories = [];

    /** @var array<string, AuthMethodInterface> */
    private array $instances = [];

    /** @param callable(AuthManager): AuthMethodInterface $factory */
    public function register(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory(...);
        unset($this->instances[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    public function isResolved(string $name): bool
    {
        return isset($this->instances[$name]);
    }

    /** @return list<string> */
    public function registered(): array
    {
        return array_keys($this->factories);
    }

    public function resolve(string $name, AuthManager $manager): AuthMethodInterface
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (!isset($this->factories[$name])) {
            throw new ConfigurationException(sprintf(
                'No auth driver registered under "%s". Register one with Auth::extend(\'%s\', fn ($manager) => ...).',
                $name,
                $name,
            ));
        }

        return $this->instances[$name] = ($this->factories[$name])($manager);
    }
}
