<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Notifications;

use Closure;
use ReyhanItsMe\AuthEngine\Contracts\NotifierInterface;

/** Wraps any callable: fn (string $channel, string $to, string $subject, string $text, array $data): void */
final class CallbackNotifier implements NotifierInterface
{
    private readonly Closure $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    public function send(string $channel, string $to, string $subject, string $text, array $data = []): void
    {
        ($this->callback)($channel, $to, $subject, $text, $data);
    }
}
