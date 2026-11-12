<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Contracts;

interface NotifiableInterface
{
    /**
     * Address for a delivery channel: an e-mail address for "email",
     * a phone number for "sms". Null when the user has none.
     */
    public function routeNotificationFor(string $channel): ?string;
}
