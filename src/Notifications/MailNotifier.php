<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Notifications;

use ReyhanItsMe\AuthEngine\Contracts\NotifierInterface;
use ReyhanItsMe\AuthEngine\Exceptions\AuthEngineException;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;

/** Sends plain-text e-mail through PHP's mail(). */
final class MailNotifier implements NotifierInterface
{
    public function __construct(private readonly ?string $from = null)
    {
    }

    public function send(string $channel, string $to, string $subject, string $text, array $data = []): void
    {
        if ($channel !== 'email') {
            throw new ConfigurationException(sprintf(
                'No sender configured for channel "%s". Provide one with Auth::setNotifier().',
                $channel,
            ));
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new AuthEngineException('Invalid e-mail recipient.');
        }

        $headers = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'];
        if ($this->from !== null && filter_var($this->from, FILTER_VALIDATE_EMAIL) !== false) {
            $headers[] = 'From: ' . $this->from;
        }

        $subject = '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $subject)) . '?=';

        if (!@mail($to, $subject, $text, implode("\r\n", $headers))) {
            throw new AuthEngineException('mail() failed to accept the message for delivery.');
        }
    }
}
