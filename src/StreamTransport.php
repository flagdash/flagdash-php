<?php

declare(strict_types=1);

namespace FlagDash;

final class StreamTransport implements Transport
{
    public function request(string $method, string $url, array $query, ?array $json, string $sdkKey, float $timeout): array
    {
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = "Authorization: Bearer {$sdkKey}\r\nAccept: application/json\r\nContent-Type: application/json\r\n";
        $context = stream_context_create(['http' => [
            'method' => strtoupper($method), 'header' => $headers,
            'content' => $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR),
            'timeout' => $timeout, 'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new FlagDashException('FlagDash request failed');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new FlagDashException("FlagDash returned HTTP {$status}", $status);
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new FlagDashException('FlagDash returned a non-object response');
        }
        return $decoded;
    }
}
