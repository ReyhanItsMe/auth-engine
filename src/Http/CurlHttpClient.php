<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Http;

use ReyhanItsMe\AuthEngine\Contracts\HttpClientInterface;
use ReyhanItsMe\AuthEngine\Exceptions\AuthEngineException;

/**
 * Small HTTPS client: uses ext-curl when present, PHP streams otherwise.
 * TLS peer verification is always on and redirects are never followed.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(private readonly int $timeout = 10)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if (!str_starts_with($url, 'https://')) {
            throw new AuthEngineException('Only https:// URLs may be requested.');
        }

        $headers += ['Accept' => 'application/json', 'User-Agent' => 'auth-engine'];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return function_exists('curl_init')
            ? $this->viaCurl($method, $url, $lines, $body)
            : $this->viaStream($method, $url, $lines, $body);
    }

    /** @param list<string> $headers */
    private function viaCurl(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curl);
        if (!is_string($response)) {
            throw new AuthEngineException('HTTP request failed: ' . curl_error($curl));
        }

        return new HttpResponse((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $response);
    }

    /** @param list<string> $headers */
    private function viaStream(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new AuthEngineException('HTTP request failed: ' . $url);
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return new HttpResponse($status, $response);
    }
}
