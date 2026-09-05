# FlagDash PHP SDK

Feature flags, remote config, AI configs, translations and experiments for PHP 8.1+.

Dependency-free: the default transport uses PHP streams, so there is nothing to
install beyond the package itself.

## Installation

```bash
composer require flagdash/sdk
```

To install straight from GitHub, add `https://github.com/flagdash/flagdash-php`
as a Composer VCS repository and require the immutable tag `v0.1.0`.

## Quick start

```php
use FlagDash\Client;

$client = new Client($_ENV['FLAGDASH_SDK_KEY']);

if ($client->flag('checkout-v2', false, ['user_id' => 'alice'])) {
    // new checkout
}

$client->close();
```

## API key tiers

The key decides which project and environment you read, and what you may reach.
There is no `environment` parameter anywhere in this SDK — the key carries it.

| Key | Prefix | Reaches |
|---|---|---|
| Client | `pk_` | Flag values and configs. Safe in a browser or mobile app. |
| Server | `sk_` | Everything the client key reaches, plus targeting rules, translations and experiments. Keep it on your server. |

Anything below marked **server key** returns nothing useful with a client key.

## Configuration

```php
$client = new Client(
    sdkKey: $_ENV['FLAGDASH_SDK_KEY'],
    baseUrl: 'https://flagdash.io',  // self-hosted? point it here
    timeout: 5.0,                    // seconds per request
    cacheTtl: 60.0,                  // seconds; 0 disables caching
    region: 'eu-west-1',             // omit to auto-detect
    transport: new StreamTransport(),
);
```

`region` is detected from `FLY_REGION`, `AWS_REGION` and friends when you leave
it out, so region-scoped targeting rules work with no wiring. `transport` takes
any `FlagDash\Transport`, which is where a PSR-18 client or a test double goes.

## Feature flags

```php
// A single flag, with a fallback used whenever FlagDash cannot be reached.
$enabled = $client->flag('checkout-v2', false, ['user_id' => 'alice']);

// Every flag for this context, in one request.
$flags = $client->allFlags(['user_id' => 'alice', 'country' => 'GB']);

// Why did it resolve that way? (server key)
$detail = $client->flagDetail('checkout-v2', false, ['user_id' => 'alice']);
// ['value' => true, 'reason' => 'rule_match', 'variation' => 'treatment', ...]

// Flag metadata without evaluating anything (server key).
$all = $client->listFlags();
```

**Pass a `user_id`** (or `key`) in the context whenever you want a stable
answer. Percentage rollouts and A/B variations hash that identifier, so an
anonymous context re-rolls on every call by design.

Context is a plain array. Any attribute you send can be targeted on:

```php
$client->flag('beta-banner', false, [
    'user_id' => 'alice',
    'country' => 'GB',
    'plan'    => 'premium',
    'email'   => 'alice@example.com',
]);
```

## Remote config

```php
$limit = $client->config('rate_limit', 100);
$all   = $client->listConfigs();
```

## AI configs

Prompts, agents, skills and rules, versioned per environment and editable
without a deploy.

```php
$prompt = $client->aiConfig('support-agent.md');
$files  = $client->listAiConfigs();
```

## Translations (server key)

```php
$greeting = $client->translation('checkout.greeting', 'fr', 'Hello', [
    'name' => 'Alice',
]);
```

The key is `namespace.message`. `{placeholders}` are filled from the variables
array, and the default is returned whenever the catalogue, the namespace or the
message is missing — a translation lookup never throws.

## Experiments (server key)

```php
$assignment = $client->experiment('checkout-redesign', ['user_id' => 'alice']);

if ($assignment !== null && $assignment['variant'] === 'treatment') {
    // ...
}

$client->trackExperimentMetric(
    experimentKey: 'checkout-redesign',
    eventName: 'purchase',
    userId: 'alice',
    value: 42.50,
    properties: ['currency' => 'GBP'],
);
```

`experiment()` returns `null` for a context with no identifier — an assignment
that cannot be stable is worse than no assignment.

Metrics are buffered in memory (up to 1000) and sent by `flush()`, which
`close()` calls for you:

```php
$client->flush();  // send now
$client->close();  // flush and release the transport
```

In a long-running worker, call `flush()` periodically. In a request/response
process, `close()` at the end of the request is enough.

## Caching

Responses are cached in memory for `cacheTtl` seconds (60 by default), so a
burst of `flag()` calls costs one request. `clearCache()` empties it.

```php
$client->clearCache();
```

## Failure behaviour

Every read returns the default you passed rather than throwing, so an outage
degrades to your fallback values instead of an exception in a request path.
`FlagDash\FlagDashException` surfaces only for programming errors such as an
empty SDK key.

## Security

Keep the server key on the server. A key granted only the scopes it needs
limits the blast radius if it leaks, and a client key never returns targeting
rules — the browser cannot see who else you are targeting.

## License

MIT
## Backend session replay

```php
$replay = new FlagDash\BackendReplay($_ENV['FLAGDASH_REPLAY_KEY'], release: '2026.08');
if ($replay->start()) {
    $replay->event('checkout_started', attributes: ['items' => 2]);
    $replay->breadcrumb('payment requested');
    $replay->stop();
}
```

The recorder captures only events you add. It redacts sensitive attribute keys, buffers bounded batches, and exposes `contextHeaders()` for cross-service correlation. Use a `replays:write` key.
