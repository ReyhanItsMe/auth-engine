<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Exceptions;

final class ValidationException extends AuthEngineException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The given data was invalid: ' . implode(' ', $errors));
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
