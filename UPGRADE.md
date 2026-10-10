# Upgrade Guide

## Upgrading To 2.0 From 1.x

2.0 is built on `laravel/ai` 1.x. Upgrade the SDK first (see its own
[upgrade guide](https://github.com/laravel/ai/blob/main/UPGRADE.md)), then:

```bash
composer require lmsomeco/laravel-ai-models:^2.0 laravel/ai:^1.0.1 -W
```

### `laravel/ai` ^1.0.1 Is Required

**Likelihood Of Impact: High**

Support for `laravel/ai` 0.x has been dropped. 1.0.1 is the minimum because
2.0 builds on `Ai::build()`.

### `AiModel::$provider` Is Now The Provider Name

**Likelihood Of Impact: High**

`AiModel::$provider` used to hold the `Lab`. It now holds the **name** of the
provider the model was listed under — its `config/ai.php` key (e.g.
`openai`) or on-demand name (e.g. `db-5`). The `Lab` moved to the new
`AiModel::$driver` property. Two providers on the same driver are now
distinguishable.

```php
// 1.x
$model->provider === Lab::OpenAI;
$model->provider->value;

// 2.0
$model->driver === Lab::OpenAI;
$model->driver->value;
$model->provider;            // 'openai', 'db-5', ...
```

`AiModel::toArray()` (and `ai:models --json`) now has `provider` as the name
and a separate `driver` key, plus `roles`. If you construct `AiModel`
yourself, pass both `provider:` and `driver:`.

### `ConnectorManager::configure()` Was Removed

**Likelihood Of Impact: High**

`configure()` wrote the connector's credentials into
`config('ai.providers.db-{id}')`. It is replaced by `provider()`, which builds
an on-demand laravel/ai provider with `Ai::build()` and leaves config
untouched. The provider is still named `db-{id}`, and it serializes with its
configuration encrypted, so it can go into queued work.

```php
// 1.x
$providerKey = $manager->configure($connector);
$agent->prompt('...', provider: $providerKey);

// 2.0 — return it from the agent's provider() method (recommended)...
public function provider(): Provider
{
    return app(ConnectorManager::class)->provider($this->connector);
}

// ...or pass its name in the same request/job that built it
$provider = $manager->provider($connector);
$agent->prompt('...', provider: $provider->name());
```

laravel/ai flushes on-demand providers between queue jobs and Octane
operations, so build the provider where the work runs.

### `ModelRegistry::driver()` And `ModelRegistry::connector()` Were Removed

**Likelihood Of Impact: Medium**

`provider()` now accepts any laravel/ai `Provider` instance, which covers both.

```php
// 1.x
AiModels::driver(Lab::OpenAI, ['key' => $key, 'url' => $url], cacheKey: 'tenant:42');
AiModels::connector($connector);

// 2.0
AiModels::provider(Ai::build(['name' => 'tenant-42', 'driver' => 'openai', 'key' => $key, 'url' => $url]));
app(ConnectorManager::class)->models($connector);
```

Results for a `Provider` instance are always cached, under its name. For an
unnamed `Ai::build()` that is the SDK's generated `ondemand_{hash}`, which is
stable for identical configuration.

### `Connector::getProvider()` Is Now `getDriver(): Lab`

**Likelihood Of Impact: Medium** (only if you implement the contract yourself)

Models using `IsConnector` or extending `AiConnector` need no change; the
`provider` column is unchanged. Hand-written implementations must rename and
retype the method:

```php
// 1.x
public function getProvider(): string
{
    return (string) $this->getAttribute('driver');
}

// 2.0
public function getDriver(): Lab
{
    return Lab::from((string) $this->getAttribute('driver'));
}
```

### Resolver Config Uses `resolver` Instead Of `driver`

**Likelihood Of Impact: Medium** (only if you published `config/ai-models.php`)

Each resolver entry names its class under `resolver`, so "driver" only ever
means the `Lab`. Re-publish the config, or rename the key:

```php
// 1.x
Lab::Groq->value => ['driver' => OpenAiCompatibleResolver::class, 'url' => '...'],

// 2.0
Lab::Groq->value => ['resolver' => OpenAiCompatibleResolver::class, 'url' => '...'],
```

While you're there, pick up the new entries: `Lab::OpenAICompatible`, the
OpenAI and Anthropic default `url`s, and `overloaded_status_codes` for
Anthropic and Groq. A published config that lacks the OpenAI `url` still works
as long as `config/ai.php` sets one, which the SDK's default config does.

### Custom Resolvers

**Likelihood Of Impact: Medium** (only if you wrote a resolver)

`ProviderResolver::provider()` now returns the laravel/ai
`Laravel\Ai\Contracts\Providers\Provider` instance instead of a `Lab`.
Resolvers are constructed with
`(HttpFactory $http, Provider $provider, array $config)`. `$config` now holds
only the resolver defaults from `config/ai-models.php` (plus `timeout`);
credentials and `url` come from the provider.

The easiest path is to extend the new `LmSomeco\AiModels\Resolvers\Resolver`
and move your `models()` body into `fetch()`. You then get key/URL lookup,
`configured()`, `newModel()` (which fills in `provider` and `driver`) and SDK
exception mapping for free:

```php
// 1.x
class GeminiResolver implements ProviderResolver
{
    public function __construct(protected HttpFactory $http, protected Lab $lab, protected array $config) {}
    public function provider(): Lab { return $this->lab; }
    public function configured(): bool { return filled($this->config['key'] ?? null); }
    public function models(): Collection { /* ... new AiModel(provider: $this->lab, ...) */ }
}

// 2.0
class GeminiResolver extends Resolver
{
    protected function fetch(): Collection { /* ... $this->newModel(id: ..., ...) */ }
}
```

Subclasses of `OpenAiCompatibleResolver` that override `mapModel()` should
build models with `$this->newModel(...)` instead of `new AiModel(...)`.

### Listing Failures Throw laravel/ai Exceptions

**Likelihood Of Impact: Low**

HTTP failures used to bubble up as `Illuminate\Http\Client\RequestException`
or `ConnectionException`. Connection errors and 429, 402 and transient 5xx
responses now throw the SDK's `ProviderConnectionException`,
`RateLimitedException`, `InsufficientCreditsException` and
`ProviderOverloadedException`. These extend `AiException`, and the original
exception is available as `getPrevious()`. Other statuses, such as 401, still
throw `RequestException`.

### Cache Keys Changed

**Likelihood Of Impact: Low**

Keys are now `{prefix}:v2:{provider-name}`. 1.x entries are never read, since
their `AiModel`s cannot be unserialized by 2.0, and they expire on their own.
Connector lists moved from `connector:{id}` to `db-{id}`, so clear them with
`AiModels::refresh('db-'.$connector->getConnectorId())`. If you cached
forever (`AI_MODELS_CACHE_TTL=null`), you may want to clear the old
`ai-models:*` keys from your store.
