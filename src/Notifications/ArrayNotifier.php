<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Notifications;

use ReyhanItsMe\AuthEngine\Contracts\NotifierInterface;

/** Keeps messages in memory. Handy for tests. */
final class ArrayNotifier implements NotifierInterface
{
    /** @var list<array{channel: string, to: string, subject: string, text: string, data: array<string, mixed>}> */
    public array $sent = [];

    public function send(string $channel, string $to, string $subject, string $text, array $data = []): void
    {
        $this->sent[] = compact('channel', 'to', 'subject', 'text', 'data');
    }

    /** @return array{channel: string, to: string, subject: string, text: string, data: array<string, mixed>}|null */
    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }
}
