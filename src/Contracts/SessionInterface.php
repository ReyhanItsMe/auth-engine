<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Contracts;

/**
 * Minimal session abstraction shared by every framework adapter.
 */
interface SessionInterface
{
    public function start(): void;

    public function getId(): string;

    public function has(string $key): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value): void;

    public function forget(string $key): void;

    /** Issue a new session ID (session fixation prevention). */
    public function regenerate(bool $destroyOld = true): void;

    /** Wipe all session data and issue a new session ID. */
    public function invalidate(): void;
}
