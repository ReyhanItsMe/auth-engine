<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Http;

final class HttpResponse
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<array-key, mixed> decoded JSON object, or [] when the body is not a JSON object/array */
    public function json(): array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : [];
    }
}
