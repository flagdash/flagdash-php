<?php

declare(strict_types=1);

namespace FlagDash;

/** Privacy-safe explicit event timeline for trusted PHP backends. */
final class BackendReplay
{
    private ?string $id = null;
    private int $sequence = 0;
    /** @var list<array<string,mixed>> */
    private array $events = [];
    private float $startedAt;
    private string $baseUrl;
    /** @var \Closure(string,string,array<string,string>,string,float):array{status:int,body:string} */
    private \Closure $transport;

    /** @param array<string,mixed> $metadata */
    public function __construct(
        private readonly string $sdkKey,
        string $baseUrl = 'https://flagdash.io',
        private readonly ?string $identity = null,
        private readonly ?string $release = null,
        private readonly array $metadata = [],
        private readonly float $timeout = 5.0,
        ?callable $transport = null,
    ) {
        if ($sdkKey === '') { throw new \InvalidArgumentException('sdkKey is required'); }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->startedAt = microtime(true);
        $this->transport = $transport === null ? $this->defaultTransport(...) : \Closure::fromCallable($transport);
    }

    public function start(): bool
    {
        try {
            $response = $this->api('/api/v1/replay-sessions/start', [
                'type' => 'trace', 'platform' => 'php', 'sdk_name' => 'flagdash-php',
                'started_at' => $this->timestamp(), 'identity' => $this->identity,
                'release' => $this->release, 'metadata' => self::asObject(self::sanitize($this->metadata)),
            ]);
            if ($response === null) { return false; }
            $this->id = (string) $response['id'];
            return true;
        } catch (\Throwable) { return false; }
    }

    /** @param array<string,mixed> $attributes */
    public function event(string $name, string $category = 'action', array $attributes = []): void
    {
        if ($this->id === null || $name === '' || count($this->events) >= 1000) { return; }
        $this->events[] = ['name' => substr($name, 0, 100), 'category' => substr($category, 0, 40),
            'timestamp' => $this->timestamp(), 'attributes' => self::asObject(self::sanitize($attributes))];
    }

    /** @param array<string,mixed> $attributes */
    public function breadcrumb(string $message, array $attributes = []): void { $this->event($message, 'breadcrumb', $attributes); }
    /** @param array<string,mixed> $attributes */
    public function captureException(\Throwable $error, array $attributes = []): void { $this->event($error::class, 'exception', $attributes); }
    /** @return array<string,string> */
    public function contextHeaders(): array { return $this->id ? ['x-flagdash-replay-id' => $this->id] : []; }

    public function flush(): bool
    {
        while ($this->id !== null && $this->events !== []) {
            $batch = array_splice($this->events, 0, 100);
            $raw = json_encode($batch, JSON_THROW_ON_ERROR);
            $manifest = $this->api("/api/v1/replay-sessions/{$this->id}/chunks/presign", [
                'sequence' => $this->sequence++, 'byte_size' => strlen($raw),
                'event_count' => count($batch), 'content_encoding' => 'identity',
            ]);
            if ($manifest === null) { return false; }
            $upload = $manifest['upload'];
            $response = ($this->transport)('PUT', $upload['url'], $upload['headers'] ?? [], $raw, $this->timeout);
            if ($response['status'] < 200 || $response['status'] >= 300) { return false; }
        }
        return true;
    }

    public function stop(): bool
    {
        if (!$this->flush()) { return false; }
        if ($this->id === null) { return true; }
        return $this->api("/api/v1/replay-sessions/{$this->id}/complete", [
            'ended_at' => $this->timestamp(), 'duration_ms' => (int) ((microtime(true) - $this->startedAt) * 1000),
        ]) !== null;
    }

    /** @param array<string,mixed> $body @return array<string,mixed>|null */
    private function api(string $path, array $body): ?array
    {
        $response = ($this->transport)('POST', $this->baseUrl . $path,
            ['Authorization' => 'Bearer ' . $this->sdkKey, 'Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR), $this->timeout);
        if ($response['status'] === 204) { return null; }
        if ($response['status'] < 200 || $response['status'] >= 300) { throw new FlagDashException("Replay HTTP {$response['status']}"); }
        return $response['body'] === '' ? [] : json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,string> $headers @return array{status:int,body:string} */
    private function defaultTransport(string $method, string $url, array $headers, string $body, float $timeout): array
    {
        $lines = [];
        foreach ($headers as $key => $value) { $lines[] = "{$key}: {$value}"; }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines),
            'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) { throw new FlagDashException('Replay request failed'); }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) { $status = (int) $matches[1]; }
        }
        return ['status' => $status, 'body' => $responseBody];
    }

    /**
     * Force a JSON object for the two map-typed fields.
     *
     * json_encode() renders an empty PHP array as `[]`, and the server stores
     * both as an Ecto `:map`, which rejects a list outright — so an empty
     * metadata or attributes silently failed the whole session with a 422.
     * Only the top level is cast: nested lists must stay arrays.
     *
     * @param mixed $value @return object|mixed
     */
    private static function asObject(mixed $value): mixed
    {
        return is_array($value) ? (object) $value : $value;
    }

    private function timestamp(): string { return gmdate('Y-m-d\TH:i:s') . sprintf('.%06dZ', (int) ((microtime(true) * 1000000) % 1000000)); }

    private static function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) { return '[REDACTED]'; }
        if (is_array($value)) {
            $clean = [];
            foreach (array_slice($value, 0, 500, true) as $key => $item) {
                $clean[$key] = is_string($key) && preg_match('/pass(word)?|secret|token|authorization|cookie|session|api[-_]?key|credit|card|cvv|cvc|otp|ssn/i', $key)
                    ? '[REDACTED]' : self::sanitize($item, $depth + 1);
            }
            return $clean;
        }
        if (is_string($value)) { return substr($value, 0, 2000); }
        return is_null($value) || is_bool($value) || is_int($value) || is_float($value) ? $value : '[REDACTED]';
    }
}
