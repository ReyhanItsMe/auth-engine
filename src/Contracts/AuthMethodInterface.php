<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Contracts;

/**
 * Marker contract for a modular authentication driver
 * (credentials, magic_link, otp, totp, webauthn, oauth).
 *
 * Drivers are registered as lazy factories and are only instantiated
 * when they are enabled in the configuration AND actually requested.
 */
interface AuthMethodInterface
{
    /** Driver key as used under the "drivers" config section. */
    public function name(): string;
}
