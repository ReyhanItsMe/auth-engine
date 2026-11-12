<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

use ReyhanItsMe\AuthEngine\Contracts\ConfigRepositoryInterface;

/**
 * Immutable configuration repository with dot-notation access.
 */
final class Config implements ConfigRepositoryInterface
{
    /** @param array<string, mixed> $items */
    public function __construct(private readonly array $items = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cursor = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();

        return $this->get($key, $missing) !== $missing;
    }

    public function all(): array
    {
        return $this->items;
    }

    /** Return a copy with a single value replaced (the original is untouched). */
    public function with(string $key, mixed $value): self
    {
        $items = $this->items;
        $cursor = &$items;

        foreach (explode('.', $key) as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor = $value;
        unset($cursor);

        return new self($items);
    }

    public function isDriverEnabled(string $name): bool
    {
        return $this->get('drivers.' . $name . '.enabled', false) === true;
    }

    /** @return list<string> */
    public function enabledDrivers(): array
    {
        $enabled = [];

        foreach ((array) $this->get('drivers', []) as $name => $options) {
            if (is_array($options) && ($options['enabled'] ?? false) === true) {
                $enabled[] = (string) $name;
            }
        }

        return $enabled;
    }
}
