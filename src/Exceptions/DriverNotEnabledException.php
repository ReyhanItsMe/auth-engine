<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Exceptions;

final class DriverNotEnabledException extends AuthEngineException
{
    public static function forDriver(string $name): self
    {
        return new self(sprintf(
            'Auth driver "%s" is disabled. Enable it with [\'drivers\' => [\'%s\' => [\'enabled\' => true]]].',
            $name,
            $name,
        ));
    }
}
