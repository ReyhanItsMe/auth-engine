<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Exceptions;

final class NotBootedException extends AuthEngineException
{
    public static function create(): self
    {
        return new self('Auth Engine has not been booted. Call Auth::boot() once during application bootstrap.');
    }
}
