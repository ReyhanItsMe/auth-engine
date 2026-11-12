<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Notifications;

use ReyhanItsMe\AuthEngine\Contracts\NotifierInterface;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;

/** Sends plain-text e-mail through Laravel's mailer (config/mail.php). */
final class LaravelNotifier implements NotifierInterface
{
    /** @param object $app the Laravel container */
    public function __construct(private readonly object $app)
    {
    }

    public function send(string $channel, string $to, string $subject, string $text, array $data = []): void
    {
        if ($channel !== 'email') {
            throw new ConfigurationException(sprintf(
                'No sender configured for channel "%s". Provide one with AuthEngine::setNotifier().',
                $channel,
            ));
        }

        $this->app->make('mailer')->raw($text, static function ($message) use ($to, $subject): void {
            $message->to($to)->subject($subject);
        });
    }
}
