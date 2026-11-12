<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Contracts;

/**
 * Delivers magic links and one-time codes.
 */
interface NotifierInterface
{
    /**
     * @param string               $channel "email" or "sms"
     * @param array<string, mixed> $data    structured payload: type (magic_link|otp), url, code, ttl, app
     */
    public function send(string $channel, string $to, string $subject, string $text, array $data = []): void;
}
