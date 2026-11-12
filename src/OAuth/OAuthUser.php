<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\OAuth;

/** Profile returned by an OAuth provider, normalised across providers. */
final class OAuthUser
{
    /** @param array<array-key, mixed> $raw the provider's original payload */
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly ?string $avatar,
        public readonly array $raw = [],
    ) {
    }
}
