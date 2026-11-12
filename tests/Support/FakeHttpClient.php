<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Support;

use ReyhanItsMe\AuthEngine\Contracts\HttpClientInterface;
use ReyhanItsMe\AuthEngine\Http\HttpResponse;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @param array<string, array{0: int, 1: mixed}> $responses url prefix => [status, json] */
    public function __construct(public array $responses = [])
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        foreach ($this->responses as $prefix => [$status, $json]) {
            if (str_starts_with($url, $prefix)) {
                return new HttpResponse($status, (string) json_encode($json));
            }
        }

        return new HttpResponse(404, '{}');
    }

    /** @return array<string, string> form fields of the n-th request body */
    public function form(int $index): array
    {
        parse_str((string) $this->requests[$index]['body'], $fields);

        return $fields;
    }
}
