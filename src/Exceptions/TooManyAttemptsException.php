<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Exceptions;

final class TooManyAttemptsException extends AuthEngineException
{
    public function __construct(private readonly int $retryAfter)
    {
        parent::__construct(sprintf('Too many attempts. Try again in %d seconds.', $retryAfter));
    }

    /** Seconds until the next attempt is allowed. */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
