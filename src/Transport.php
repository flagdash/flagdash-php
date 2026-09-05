<?php

declare(strict_types=1);

namespace FlagDash;

interface Transport
{
    /** @param array<string, scalar|null> $query @param array<string, mixed>|null $json @return array<string, mixed> */
    public function request(string $method, string $url, array $query, ?array $json, string $sdkKey, float $timeout): array;
}
