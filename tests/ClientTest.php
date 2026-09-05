<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Transport.php';
require_once __DIR__ . '/../src/StreamTransport.php';
require_once __DIR__ . '/../src/FlagDashException.php';
require_once __DIR__ . '/../src/Client.php';
require_once __DIR__ . '/../src/BackendReplay.php';

use FlagDash\Client;
use FlagDash\BackendReplay;
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

$replayCalls = [];
$replayTransport = function (string $method, string $url, array $headers, string $body, float $timeout) use (&$replayCalls): array {
    $replayCalls[] = compact('method', 'url', 'headers', 'body');
    return match (parse_url($url, PHP_URL_PATH)) {
        '/api/v1/replay-sessions/start' => ['status' => 201, 'body' => '{"id":"rpl_php"}'],
        '/api/v1/replay-sessions/rpl_php/chunks/presign' => ['status' => 200, 'body' => '{"upload":{"url":"https://storage.test/chunk","headers":{}}}'],
        default => ['status' => 200, 'body' => '{}'],
    };
};
$replay = new BackendReplay('sk_test', 'https://example.test', metadata: ['api_key' => 'hidden'], transport: $replayTransport);
assert($replay->start());
$replay->event('checkout_started', attributes: ['password' => 'hidden', 'items' => 2]);
assert($replay->contextHeaders()['x-flagdash-replay-id'] === 'rpl_php');
assert($replay->stop());
$uploaded = array_values(array_filter($replayCalls, fn (array $call): bool => parse_url($call['url'], PHP_URL_HOST) === 'storage.test'))[0]['body'];
assert(str_contains($uploaded, 'checkout_started'));
assert(!str_contains($uploaded, '"hidden"'));

// An empty metadata/attributes must serialise as a JSON object, not `[]`.
// The server stores both as an Ecto `:map`, which rejects a list outright, so
// `[]` failed every session that did not happen to pass metadata -- and every
// assertion above passes non-empty maps, which is exactly why this went unseen.
$emptyCalls = [];
$emptyTransport = function (string $method, string $url, array $headers, string $body, float $timeout) use (&$emptyCalls): array {
    $emptyCalls[] = compact('url', 'body');
    return match (parse_url($url, PHP_URL_PATH)) {
        '/api/v1/replay-sessions/start' => ['status' => 201, 'body' => '{"id":"rpl_empty"}'],
        '/api/v1/replay-sessions/rpl_empty/chunks/presign' => ['status' => 200, 'body' => '{"upload":{"url":"https://storage.test/chunk","headers":{}}}'],
        default => ['status' => 200, 'body' => '{}'],
    };
};
$empty = new BackendReplay('sk_test', 'https://example.test', transport: $emptyTransport);
assert($empty->start());
$empty->event('probe');
assert($empty->stop());
assert(str_contains($emptyCalls[0]['body'], '"metadata":{}'));
$emptyUpload = array_values(array_filter($emptyCalls, fn (array $call): bool => parse_url($call['url'], PHP_URL_HOST) === 'storage.test'))[0]['body'];
assert(str_contains($emptyUpload, '"attributes":{}'));

// ...while a genuine nested list must stay a list.
$listCalls = [];
$listTransport = function (string $method, string $url, array $headers, string $body, float $timeout) use (&$listCalls): array {
    $listCalls[] = compact('url', 'body');
    return ['status' => 200, 'body' => '{"id":"rpl_list"}'];
};
$list = new BackendReplay('sk_test', 'https://example.test', metadata: ['tags' => ['a', 'b']], transport: $listTransport);
assert($list->start());
assert(str_contains($listCalls[0]['body'], '"tags":["a","b"]'));

echo "FlagDash PHP SDK tests passed\n";
