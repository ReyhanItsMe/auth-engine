<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Notifications;

use ReyhanItsMe\AuthEngine\Contracts\NotifierInterface;

/**
 * Writes messages to the PHP error log instead of delivering them.
 * Development only: links and codes end up in plain text in the log.
 */
final class LogNotifier implements NotifierInterface
{
    public function send(string $channel, string $to, string $subject, string $text, array $data = []): void
    {
        error_log(sprintf('[auth-engine] %s to %s | %s | %s', $channel, $to, $subject, str_replace(["\r", "\n"], ' ', $text)));
    }
}
