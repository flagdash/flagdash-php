<?php

declare(strict_types=1);

namespace FlagDash;

final class Client
{
    private const REGION_ENV = ['FLAGDASH_REGION', 'FLY_REGION', 'AWS_REGION', 'AWS_DEFAULT_REGION', 'VERCEL_REGION', 'GOOGLE_CLOUD_REGION', 'RAILWAY_REPLICA_REGION', 'RENDER_REGION'];
    /** @var array<string, array{value: mixed, expires: float}> */
    private array $cache = [];
    /** @var list<array<string, mixed>> */
    private array $events = [];
    private readonly string $baseUrl;
    private readonly ?string $region;

    public function __construct(
        private readonly string $sdkKey,
        string $baseUrl = 'https://flagdash.io',
        private readonly float $timeout = 5.0,
        private readonly float $cacheTtl = 60.0,
        ?string $region = null,
        private readonly Transport $transport = new StreamTransport(),
    ) {
        if ($sdkKey === '') {
            throw new \InvalidArgumentException('sdkKey is required');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->region = $region ?? $this->detectRegion();
    }

    /** @param array<string, mixed> $context */
    public function flag(string $key, mixed $default = false, array $context = []): mixed
    {
        if ($context !== []) {
            return $this->flagDetail($key, $default, $context)['value'];
        }
        return $this->cached("flag:{$key}", fn (): mixed => $this->allFlags()[$key] ?? $default);
    }

    /** @param array<string, mixed> $context @return array{key: string, value: mixed, reason: string, variation_key: mixed} */
    public function flagDetail(string $key, mixed $default = null, array $context = []): array
    {
        try {
            $flag = $this->request('GET', '/server/flags/' . $this->segment($key), $this->context($context))['flag'];
            return ['key' => $flag['key'] ?? $key, 'value' => $flag['evaluated_value'] ?? $default,
                'reason' => $flag['evaluation_path'] ?? 'default', 'variation_key' => $flag['variation_key'] ?? null];
        } catch (\Throwable) {
            return ['key' => $key, 'value' => $default, 'reason' => 'default', 'variation_key' => null];
        }
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function allFlags(array $context = []): array
    {
        if ($context === []) {
            return $this->cached('all_flags', fn (): array => $this->fetchFlags([]));
        }
        return $this->fetchFlags($context);
    }

    /** @return list<array<string, mixed>> */
    public function listFlags(): array { return $this->request('GET', '/server/flags', $this->context([]))['flags'] ?? []; }

    public function config(string $key, mixed $default = null): mixed
    {
        try {
            return $this->cached("config:{$key}", fn (): mixed => $this->request('GET', '/server/configs/' . $this->segment($key))['config']['value'] ?? $default);
        } catch (\Throwable) { return $default; }
    }

    /** @return list<array<string, mixed>> */
    public function listConfigs(): array { return $this->request('GET', '/server/configs')['configs'] ?? []; }

    /** @return array<string, mixed>|null */
    public function aiConfig(string $fileName): ?array
    {
        try { return $this->cached("ai:{$fileName}", fn (): ?array => $this->request('GET', '/server/ai-configs/' . $this->segment($fileName))['ai_config'] ?? null); }
        catch (\Throwable) { return null; }
    }

    /** @return list<array<string, mixed>> */
    public function listAiConfigs(): array { return $this->request('GET', '/server/ai-configs')['ai_configs'] ?? []; }

    /** @param array<string, scalar> $variables */
    public function translation(string $key, string $locale, ?string $default = null, array $variables = []): string
    {
        [$namespace, $message] = array_pad(explode('.', $key, 2), 2, null);
        if ($message === null) { return $default ?? $key; }
        try {
            $catalog = $this->cached("translation:{$locale}:{$namespace}", fn (): array => $this->request('GET', '/server/translations/' . $this->segment($locale) . '/' . $this->segment($namespace))['catalog'] ?? []);
            $pattern = $catalog['messages'][$message] ?? null;
            if (!is_string($pattern)) { return $default ?? $key; }
            return preg_replace_callback('/\{([\w.]+)\}/', fn (array $match): string => (string) ($variables[$match[1]] ?? $match[0]), $pattern) ?? $pattern;
        } catch (\Throwable) { return $default ?? $key; }
    }

    /** @param array<string, mixed> $context @return array<string, mixed>|null */
    public function experiment(string $key, array $context): ?array
    {
        if (!$this->identity($context)) { return null; }
        try { return $this->request('GET', '/server/experiments/' . $this->segment($key), $this->context($context))['experiment'] ?? null; }
        catch (\Throwable) { return null; }
    }

    /** @param array<string, mixed> $properties */
    public function trackExperimentMetric(string $experimentKey, string $eventName, string $userId, ?float $value = null, array $properties = []): void
    {
        if (count($this->events) >= 1000) { return; }
        $this->events[] = ['event_id' => 'evt_' . bin2hex(random_bytes(16)), 'experiment_key' => $experimentKey,
            'event_name' => $eventName, 'user_id' => $userId, 'value' => $value, 'properties' => $properties,
            'occurred_at' => gmdate('c')];
    }

    public function flush(): bool
    {
        try {
            while ($this->events !== []) {
                $batch = array_slice($this->events, 0, 100);
                $this->request('POST', '/server/experiment-events/batch', [], ['events' => $batch]);
                array_splice($this->events, 0, count($batch));
            }
            return true;
        } catch (\Throwable) { return false; }
    }

    public function clearCache(): void { $this->cache = []; }
    public function close(): bool { return $this->flush(); }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function fetchFlags(array $context): array
    {
        $values = $this->request('GET', '/server/flags', $this->context($context))['evaluated'] ?? [];
        if ($context === []) { foreach ($values as $key => $value) { $this->put("flag:{$key}", $value); } }
        return $values;
    }

    private function cached(string $key, callable $loader): mixed
    {
        $hit = $this->cache[$key] ?? null;
        if ($this->cacheTtl > 0 && $hit !== null && $hit['expires'] > hrtime(true) / 1e9) { return $hit['value']; }
        $value = $loader();
        if ($this->cacheTtl > 0) { $this->put($key, $value); }
        return $value;
    }

    private function put(string $key, mixed $value): void { $this->cache[$key] = ['value' => $value, 'expires' => hrtime(true) / 1e9 + $this->cacheTtl]; }

    /** @param array<string, scalar|null> $query @param array<string, mixed>|null $json @return array<string, mixed> */
    private function request(string $method, string $path, array $query = [], ?array $json = null): array
    { return $this->transport->request($method, $this->baseUrl . '/api/v1' . $path, $query, $json, $this->sdkKey, $this->timeout); }

    /** @param array<string, mixed> $context @return array<string, scalar|null> */
    private function context(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            if ($key === 'user' && is_array($value)) {
                foreach ($value as $userKey => $userValue) { if (is_scalar($userValue) || $userValue === null) { $result[$userKey === 'id' ? 'user_id' : "user_{$userKey}"] = $userValue; } }
            } elseif (is_scalar($value) || $value === null) { $result[$key] = $value; }
        }
        if ($this->region !== null && !isset($result['region'])) { $result['region'] = $this->region; }
        return $result;
    }

    /** @param array<string, mixed> $context */
    private function identity(array $context): bool { return isset($context['user_id']) || isset($context['unit_id']) || isset($context['user']['id']); }
    private function segment(string $value): string { return rawurlencode($value); }
    private function detectRegion(): ?string { foreach (self::REGION_ENV as $name) { $value = getenv($name); if ($value !== false && $value !== '') { return $value; } } return null; }
}
