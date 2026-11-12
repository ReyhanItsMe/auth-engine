<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Contracts;

/**
 * Bridges the framework-agnostic core to its host environment
 * (native PHP, Laravel, ...).
 */
interface FrameworkAdapterInterface
{
    /** Short adapter identifier, e.g. "native" or "laravel". */
    public function name(): string;

    public function session(): SessionInterface;

    public function ipAddress(): ?string;

    public function userAgent(): ?string;

    /** Base URL of the application without a trailing slash. */
    public function baseUrl(): string;
}
