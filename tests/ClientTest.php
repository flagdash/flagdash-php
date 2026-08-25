<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Transport.php';
require_once __DIR__ . '/../src/StreamTransport.php';
require_once __DIR__ . '/../src/FlagDashException.php';
require_once __DIR__ . '/../src/Client.php';

use FlagDash\Client;
use FlagDash\Transport;

$transport = new class implements Transport {
    public array $calls = [];
    public function request(string $method, string $url, array $query, ?array $json, string $sdkKey, float $timeout): array {
        $this->calls[] = compact('method', 'url', 'query', 'json');
        return match (parse_url($url, PHP_URL_PATH)) {
            '/api/v1/server/flags' => ['evaluated' => ['checkout' => true]],
            '/api/v1/server/flags/checkout' => ['flag' => ['key' => 'checkout', 'evaluated_value' => true, 'evaluation_path' => 'rule_match']],
            '/api/v1/server/configs/theme' => ['config' => ['value' => 'violet']],
            '/api/v1/server/ai-configs/agent.md' => ['ai_config' => ['content' => 'Be useful']],
            '/api/v1/server/translations/en/common' => ['catalog' => ['messages' => ['welcome' => 'Hello {name}']]],
            '/api/v1/server/experiments/test' => ['experiment' => ['variant_key' => 'b']],
            default => ['accepted' => count($json['events'] ?? [])],
        };
    }
};
$client = new Client('sk_test', 'https://example.test', region: 'eu', transport: $transport);
assert($client->flag('checkout') === true);
assert($client->flagDetail('checkout', false, ['user' => ['id' => 'alice']])['reason'] === 'rule_match');
assert($client->config('theme') === 'violet');
assert($client->aiConfig('agent.md')['content'] === 'Be useful');
assert($client->translation('common.welcome', 'en', variables: ['name' => 'Ada']) === 'Hello Ada');
assert($client->experiment('test', ['user_id' => 'alice'])['variant_key'] === 'b');
$client->trackExperimentMetric('test', 'purchase', 'alice');
assert($client->flush());
echo "FlagDash PHP SDK tests passed\n";
