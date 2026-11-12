<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Contracts;

use ReyhanItsMe\AuthEngine\Http\HttpResponse;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
