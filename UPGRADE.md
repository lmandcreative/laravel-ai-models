# Upgrade Guide

- [Upgrading To 2.0 From 1.x](#upgrading-to-20-from-1x)

## Upgrading To 2.0 From 1.x

> **Estimated upgrade time: 10–30 minutes** for an application that lists
> models and uses database connectors. Allow longer if you wrote your own
> resolvers or `Connector` implementation.

2.0 moves the package onto the Laravel AI SDK 1.x. Providers are now resolved
through the SDK's `AiManager` instead of being read from raw config arrays:

- database connectors become on-demand providers built with `Ai::build()`;
- models carry the roles the SDK assigns them (default, cheapest, smartest, ...);
- listing failures raise the SDK's failover exceptions.

Every change is listed in the [CHANGELOG](CHANGELOG.md#200---unreleased).
This guide walks through what you need to do about each one.

### Contents

- [Before you start](#before-you-start)
- [Upgrade checklist](#upgrade-checklist)
- [Step 1: Update dependencies](#step-1-update-dependencies)
- [Step 2: Update your published config](#step-2-update-your-published-config)
- [Step 3: Find affected code](#step-3-find-affected-code)
- High impact changes
  - [`laravel/ai` ^1.0.1 is required](#laravelai-101-is-required)
  - [`AiModel::$provider` is now the provider name](#aimodelprovider-is-now-the-provider-name)
  - [Resolver config uses `resolver` instead of `driver`](#resolver-config-uses-resolver-instead-of-driver)
  - [`ConnectorManager::configure()` was removed](#connectormanagerconfigure-was-removed)
- Medium impact changes
  - [`ModelRegistry::driver()` and `connector()` were removed](#modelregistrydriver-and-connector-were-removed)
  - [`Connector::getProvider()` is now `getDriver(): Lab`](#connectorgetprovider-is-now-getdriver-lab)
  - [Custom resolvers](#custom-resolvers)
  - [Constructor changes](#constructor-changes)
- Low impact changes
  - [Listing failures throw laravel/ai exceptions](#listing-failures-throw-laravelai-exceptions)
  - [Cache keys changed](#cache-keys-changed)
  - [Providers are resolved (and cached) by the SDK](#providers-are-resolved-and-cached-by-the-sdk)
  - [Providers that may newly appear](#providers-that-may-newly-appear)
  - [A base URL is now required](#a-base-url-is-now-required)
  - [`ai:models` output](#aimodels-output)
- [Updating your tests](#updating-your-tests)
- [New features worth adopting](#new-features-worth-adopting)
- [Troubleshooting](#troubleshooting)
- [Rolling back](#rolling-back)

### Before you start

Upgrade the Laravel AI SDK itself to 1.x first and follow **its**
[upgrade guide](https://github.com/laravel/ai/blob/1.x/UPGRADE.md). The SDK's
own breaking changes don't involve this package but will block the install.
The main ones:

- `laravel/mcp` must be `^1.0` if you use it.
- The `agent_conversation_messages` table replaces its `tool_calls` and
  `tool_results` columns with `steps`, which needs a migration.
- `aws/aws-sdk-php` is no longer installed by the SDK. Require it yourself if
  you use Bedrock.

### Upgrade checklist

| # | Change | Affects you if... | Impact |
|---|--------|-------------------|--------|
| 1 | [`laravel/ai` ^1.0.1 required](#laravelai-101-is-required) | always | High |
| 2 | [`AiModel::$provider` is a name, `$driver` is the Lab](#aimodelprovider-is-now-the-provider-name) | you read `$model->provider`, or consume `toArray()` / `--json` / API output | High |
| 3 | [`resolver` config key](#resolver-config-uses-resolver-instead-of-driver) | you published `config/ai-models.php` | High |
| 4 | [`ConnectorManager::configure()` removed](#connectormanagerconfigure-was-removed) | you use database connectors to prompt | High |
| 5 | [`driver()` / `connector()` removed](#modelregistrydriver-and-connector-were-removed) | you call `AiModels::driver()` or `AiModels::connector()` | Medium |
| 6 | [`Connector::getDriver(): Lab`](#connectorgetprovider-is-now-getdriver-lab) | you implement `Connector` by hand | Medium |
| 7 | [Resolver contract & constructor](#custom-resolvers) | you wrote or extended a resolver | Medium |
| 8 | [Constructor changes](#constructor-changes) | you `new` up `AiModel`, `ModelRegistry` or `ConnectorManager` | Medium |
| 9 | [SDK exceptions](#listing-failures-throw-laravelai-exceptions) | you catch `RequestException` / `ConnectionException` around listings | Low |
| 10 | [Cache keys](#cache-keys-changed) | you clear keys by hand, or cache forever | Low |
| 11 | [SDK-cached provider instances](#providers-are-resolved-and-cached-by-the-sdk) | you change `config('ai.providers')` at runtime | Low |
| 12 | [Newly listed providers](#providers-that-may-newly-appear) | you use `Lab` drivers in config or the `openai-compatible` entry | Low |
| 13 | [Base URL required](#a-base-url-is-now-required) | a provider of yours has no URL and no resolver fallback | Low |
| 14 | [`ai:models` output](#aimodels-output) | you parse the command's table or JSON | Low |

### Step 1: Update dependencies

```bash
composer require lmsomeco/laravel-ai-models:^2.0 laravel/ai:^1.0.1 -W
```

PHP 8.3+ and Laravel 12 or 13 are still required, as in 1.x.

### Step 2: Update your published config

If you **never published** `config/ai-models.php`, skip this step. The
package's own config is merged in automatically.

If you **did**, either re-publish it (back up your changes first):

```bash
php artisan vendor:publish --tag=ai-models-config --force
```

or edit it by hand:

1. In `resolvers`, rename each entry's `'driver' =>` key to `'resolver' =>`.
2. Add the new `Lab::OpenAICompatible` entry if you want
   `openai-compatible` providers listed.
3. Add the new `url` fallbacks for OpenAI and Anthropic and the
   `overloaded_status_codes` for Anthropic and Groq.

See [the full section](#resolver-config-uses-resolver-instead-of-driver) for
the new file.

### Step 3: Find affected code

These searches find most of what needs attention. Run them from your
application root:

```bash
# AiModel::$provider used as a Lab (now $driver) — expect some unrelated hits
grep -rn -- '->provider\b' app/ resources/views/ routes/ tests/

# Removed methods
grep -rnE -- '(AiModels::|->)(driver|connector)\(|->configure\(\$' app/ routes/ tests/

# Hand-written Connector implementations
grep -rn 'implements Connector\|function getProvider' app/

# Custom resolvers
grep -rnE 'implements ProviderResolver|extends (OpenAiCompatible|OpenAi|OpenRouter|Anthropic)Resolver' app/

# Manual construction
grep -rnE 'new (AiModel|ModelRegistry|ConnectorManager)\(' app/ tests/

# Hard-coded cache keys
grep -rn "ai-models:" app/ config/ routes/ tests/

# JSON consumers (Vue/React/Inertia components, API resources)
grep -rnE "\.provider\b|\['provider'\]" resources/js/ app/Http/Resources/
```

---

### `laravel/ai` ^1.0.1 Is Required

**Likelihood Of Impact: High**

Support for `laravel/ai` 0.7–0.11 has been dropped. 1.0.1 is the minimum
because 2.0 uses `Ai::build()`, which first shipped in 1.0.1. If you can't
move to the 1.x SDK yet, stay on `lmsomeco/laravel-ai-models:^1.0.6`.

### `AiModel::$provider` Is Now The Provider Name

**Likelihood Of Impact: High**

In 1.x, `AiModel::$provider` held the `Lab` of the driver that served the
model. That made two providers on the same driver indistinguishable: your
`openai` config entry and a `db-5` connector pointing at OpenAI both reported
`Lab::OpenAI`.

In 2.0:

- `AiModel::$provider` is the **name** of the provider the model was listed
  under (`string`). That is its `config/ai.php` key (e.g. `openai`,
  `openai-eu`) or its on-demand name (e.g. `db-5`, `tenant-42`, or the SDK's
  generated `ondemand_{hash}`).
- The new `AiModel::$driver` holds the `Lab`.

```php
// 1.x
if ($model->provider === Lab::OpenAI) { /* ... */ }
$label = $model->provider->value;

// 2.0
if ($model->driver === Lab::OpenAI) { /* ... */ }
$label = $model->driver->value;

$model->provider; // 'openai', 'db-5', ...
```

Grouping models by provider works as before, but now groups by name:

```php
// 1.x — keyed by Lab value: all OpenAI-backed providers merged together
AiModels::all()->groupBy(fn ($m) => $m->provider->value);

// 2.0 — keyed by provider name; use ->driver->value for the old behaviour
AiModels::all()->groupBy('provider');
AiModels::all()->groupBy(fn ($m) => $m->driver->value);
```

#### Array / JSON output

`AiModel::toArray()` (and therefore `ai:models --json`, and any API response
or Inertia prop that serializes models) changed shape:

```jsonc
// 1.x
{ "provider": "openai", "id": "gpt-5.5", "name": "gpt-5.5", ... }

// 2.0
{
  "provider": "openai",          // the provider NAME (e.g. "db-5" for a connector)
  "driver": "openai",            // NEW: the Lab value
  "id": "gpt-5.5",
  "name": "gpt-5.5",
  "context_window": null,
  "max_output_tokens": null,
  "modalities": ["text", "image"],
  "output_modalities": ["text"],
  "roles": ["text"],             // NEW: see "Model roles"
  "created_at": "2026-04-23T00:00:00+00:00"
}
```

Providers whose config key equals their driver (`openai`, `anthropic`, ...)
keep the same `provider` string, so most front-ends keep working unchanged.
Connector and custom-named providers now report their own name. If a
front-end component uses `provider` to choose an icon or label per lab,
switch it to `driver`:

```vue
<!-- 1.x -->
<ProviderIcon :lab="model.provider" />

<!-- 2.0 -->
<ProviderIcon :lab="model.driver" />
```

If you type these payloads in TypeScript, add `driver: string` and
`roles: string[]`.

### Resolver Config Uses `resolver` Instead Of `driver`

**Likelihood Of Impact: High** (only if you published `config/ai-models.php`)

Each entry in `ai-models.resolvers` now names its class under `resolver`, so
"driver" only ever means the `Lab`. Laravel merges a package config only at
the top level, so a published 1.x `resolvers` array replaces the shipped one
entirely. Until it's updated, **no provider matches a resolver**:
`ai:models` lists nothing, and `AiModels::provider('openai')` throws
`No model resolver registered for provider [openai] (driver: openai).`

```php
// 1.x
Lab::Groq->value => [
    'driver' => OpenAiCompatibleResolver::class,
    'url' => 'https://api.groq.com/openai/v1',
],

// 2.0
Lab::Groq->value => [
    'resolver' => OpenAiCompatibleResolver::class,
    'url' => 'https://api.groq.com/openai/v1',
    'overloaded_status_codes' => [498, 502, 503],
],
```

The complete 2.0 `resolvers` block:

```php
'resolvers' => [
    Lab::OpenAI->value => [
        'resolver' => OpenAiResolver::class,
        'url' => 'https://api.openai.com/v1',
    ],
    Lab::Groq->value => [
        'resolver' => OpenAiCompatibleResolver::class,
        'url' => 'https://api.groq.com/openai/v1',
        'overloaded_status_codes' => [498, 502, 503],
    ],
    Lab::Mistral->value => [
        'resolver' => OpenAiCompatibleResolver::class,
        'url' => 'https://api.mistral.ai/v1',
    ],
    Lab::DeepSeek->value => [
        'resolver' => OpenAiCompatibleResolver::class,
        'url' => 'https://api.deepseek.com',
    ],
    Lab::xAI->value => [
        'resolver' => OpenAiCompatibleResolver::class,
        'url' => 'https://api.x.ai/v1',
    ],
    Lab::OpenAICompatible->value => [
        'resolver' => OpenAiCompatibleResolver::class,
        'requires_key' => false,
    ],
    Lab::OpenRouter->value => [
        'resolver' => OpenRouterResolver::class,
        'url' => 'https://openrouter.ai/api/v1',
    ],
    Lab::Anthropic->value => [
        'resolver' => AnthropicResolver::class,
        'url' => 'https://api.anthropic.com/v1',
        'version' => '2023-06-01',
        'overloaded_status_codes' => [529, 502, 503, 504, 520, 522, 524],
    ],
],
```

The other top-level keys (`cache`, `timeout`, `connectors`) are unchanged.

### `ConnectorManager::configure()` Was Removed

**Likelihood Of Impact: High** (if you prompt through database connectors)

In 1.x, `configure()` decrypted the connector's key and wrote it into
`config('ai.providers.db-{id}')` for the rest of the process. That mutation
is gone. `ConnectorManager::provider()` instead builds an **on-demand**
laravel/ai provider with `Ai::build()`. It's still named `db-{id}`, but it
lives only in the SDK's `AiManager`, never in config.

**Option A — return it from the agent (recommended).** This is the SDK's
documented place for on-demand providers, and it works for queued and
broadcast agents too:

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Promptable;
use LmSomeco\AiModels\ConnectorManager;

class SupportAgent implements Agent
{
    use Promptable;

    public function __construct(public ?string $connectorId = null) {}

    public function provider(): Provider
    {
        $manager = app(ConnectorManager::class);

        return $manager->provider($manager->resolve($this->connectorId));
    }

    public function instructions(): string
    {
        return 'You are a helpful support agent.';
    }
}

(new SupportAgent($connectorId))->prompt('Where is my order?');
```

**Option B — pass the provider's name.** This is the closest drop-in
replacement for `configure()`. It works for the rest of the current request,
command or job:

```php
// 1.x
$providerKey = $manager->configure($connector);            // "db-{id}"
$response = (new SupportAgent)->prompt('...', provider: $providerKey);

// 2.0
$provider = $manager->provider($connector);                 // name(): "db-{id}"
$response = (new SupportAgent)->prompt('...', provider: $provider->name());
```

Pass `->name()`, not the `Provider` object itself. `prompt()`'s `$provider`
parameter is typed `Lab|array|string|null`, so passing the object only works
through string coercion and throws a `TypeError` in files that declare
`strict_types=1`.

Some 1.x behaviour went away with `configure()`, so check whether you relied
on it:

- **`config('ai.providers.db-{id}')` no longer exists.** Code that read it, or
  that passed `'db-{id}'` to something reading `config('ai.providers')`
  directly, must use the `Provider` object instead:
  `$provider->providerCredentials()` and
  `$provider->additionalConfiguration()`.
- **The `db-{id}` name only lasts for the current operation.** laravel/ai
  flushes on-demand providers between queue jobs and between Octane requests.
  A job that receives the name `'db-5'` as a string, but didn't build the
  provider itself, fails with
  `On-demand provider [db-5] was not built in this process.`

  For queued work, use Option A, or dispatch the `Provider` object itself. It
  serializes with its configuration encrypted with `APP_KEY` and rebuilds
  itself when the job unserializes it.
- **Name collisions are now an error.** If your app *also* writes
  `ai.providers.db-{id}` into config (e.g. leftover 1.x-style code),
  `provider()` throws `The provider name [db-{id}] is already taken.` Remove
  the config injection.

### `ModelRegistry::driver()` And `connector()` Were Removed

**Likelihood Of Impact: Medium**

`AiModels::provider()` now accepts any laravel/ai `Provider` instance, which
covers both removed methods:

```php
use Laravel\Ai\Ai;
use LmSomeco\AiModels\ConnectorManager;
use LmSomeco\AiModels\Facades\AiModels;

// 1.x
AiModels::driver(Lab::OpenAI, ['key' => $key, 'url' => $url], cacheKey: 'tenant:42');
AiModels::driver(Lab::OpenAI, ['key' => $key]);              // uncached
AiModels::connector($connector);

// 2.0
AiModels::provider(Ai::build([
    'name' => 'tenant-42',                                    // cache key: ai-models:v2:tenant-42
    'driver' => 'openai',
    'key' => $key,
    'url' => $url,
]));
AiModels::provider(Ai::build(['driver' => 'openai', 'key' => $key]), fresh: true);
app(ConnectorManager::class)->models($connector);
```

Differences to be aware of:

- **Results are always cached**, under the provider's `name()`. There is no
  "uncached" mode any more. Pass `fresh: true` to bypass and rewrite the
  entry for one call. An unnamed `Ai::build()` gets the SDK's generated name
  `ondemand_{md5 of its config}`, which is stable for identical config, so
  repeated calls still hit the cache.
- **Names must be unique.** `Ai::build()` rejects a `name` that already
  exists in `config('ai.providers')` or equals a `Lab` value (`openai`,
  `anthropic`, ...), with `The provider name [...] is already taken.`
- **Unknown drivers** now throw
  `No model resolver registered for provider [{name}] (driver: {driver}).`
  The 1.x message was `... for driver [{driver}].`

### `Connector::getProvider()` Is Now `getDriver(): Lab`

**Likelihood Of Impact: Medium** (only if you implement the contract yourself)

Models that use `IsConnector` or extend `AiConnector` **need no change**.
The `provider` column, migration and stored values are unchanged.

Hand-written `Connector` implementations must rename the method and return a
`Lab`:

```php
use Laravel\Ai\Enums\Lab;

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

`Lab::from()` throws a `ValueError` for values that aren't `Lab` cases.
Check your stored data for typos or retired drivers before deploying:

```php
use Laravel\Ai\Enums\Lab;

YourConnector::query()->pluck('driver')->unique()
    ->reject(fn ($v) => Lab::tryFrom($v))
    ->dd();
```

If you cast the column to `Lab` on your model (`'driver' => Lab::class`), you
can return the attribute directly.

### Custom Resolvers

**Likelihood Of Impact: Medium** (only if you wrote or extended a resolver)

Four things changed in the resolver contract:

1. **`ProviderResolver::provider()` returns the SDK `Provider` instance**
   (`Laravel\Ai\Contracts\Providers\Provider`), not a `Lab`.
2. **Constructor:** resolvers are built with named arguments
   `(HttpFactory $http, Provider $provider, array $config)`. The 1.x shape was
   `(HttpFactory $http, Lab $lab, array $config)`. Your constructor
   parameters must be named `provider` and `config`.
3. **`$config` no longer contains credentials.** In 1.x it was the
   `config/ai.php` entry merged over the resolver defaults. Now it holds only
   the resolver defaults from `config/ai-models.php` (`url`, `version`,
   `requires_key`, `overloaded_status_codes`, your own keys) plus `timeout`.
   The key and URL come from the provider. **Code reading
   `$this->config['key']` silently gets `null`** — use `$this->apiKey()`.
4. **The shipped resolvers extend the new abstract `Resolver`**, whose
   `models()` is `final`. Put your fetching logic in `fetch()`.

#### Migrating a resolver that implemented `ProviderResolver` directly

Extend `Resolver` and move your `models()` body into `fetch()`. You get
`configured()`, key/URL lookup, `newModel()` and SDK exception mapping for
free:

```php
// 1.x
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Contracts\ProviderResolver;
use LmSomeco\AiModels\Data\AiModel;

class GeminiResolver implements ProviderResolver
{
    public function __construct(
        protected HttpFactory $http,
        protected Lab $lab,
        protected array $config,
    ) {}

    public function provider(): Lab
    {
        return $this->lab;
    }

    public function configured(): bool
    {
        return filled($this->config['key'] ?? null);
    }

    public function models(): Collection
    {
        $response = $this->http
            ->baseUrl($this->config['url'])
            ->timeout($this->config['timeout'] ?? 15)
            ->get('/models', ['key' => $this->config['key']])
            ->throw();

        return collect($response->json('models'))->map(fn ($m) => new AiModel(
            provider: $this->lab,
            id: str($m['name'])->after('models/')->toString(),
            name: $m['displayName'] ?? null,
        ))->sortBy('id')->values();
    }
}
```

```php
// 2.0
use LmSomeco\AiModels\Resolvers\Resolver;

class GeminiResolver extends Resolver
{
    protected function fetch(): Collection
    {
        $response = $this->request()                    // base URL + timeout + JSON
            ->get('/models', ['key' => $this->apiKey()])
            ->throw();                                  // mapped to SDK exceptions by models()

        return collect($response->json('models'))->map(fn ($m) => $this->newModel(
            id: str($m['name'])->after('models/')->toString(),   // provider + driver filled in
            name: $m['displayName'] ?? null,
        ))->sortBy('id')->values();
    }
}
```

The base class's `configured()` returns `true` when there's a base URL and,
unless `requires_key` is `false`, an API key. Override it if your provider
needs something else, e.g. OpenRouter needs no key at all. If you must keep
implementing `ProviderResolver` directly, update `provider()` and your
constructor as described above.

#### Migrating a subclass of a shipped resolver

| You overrode / used...                      | In 2.0 |
|---------------------------------------------|--------|
| `models()`                                  | Override `fetch()` instead (`models()` is `final`). |
| `mapModel()` returning `new AiModel(provider: $this->provider(), ...)` | Return `$this->newModel(...)` (no `provider:`/`driver:` arguments). |
| `apiKey()` / `baseUrl()` reading `$this->config['key']` / `['url']` | Still overridable (now on `Resolver`), but read from `$this->provider` — the config no longer has them. |
| `$this->lab`                                | `$this->driver()` (a `Lab`) or `$this->provider` (the SDK provider). |
| `$this->provider()` expecting a `Lab`       | Returns the SDK `Provider`; use `$this->driver()`. |
| `request()`                                 | Unchanged in purpose; call `parent::request()` to keep base URL, JSON and timeout. |

#### Registering it

Use the `resolver` key (see
[above](#resolver-config-uses-resolver-instead-of-driver)), and move any URL
default you had hard-coded into the entry:

```php
Lab::Gemini->value => [
    'resolver' => \App\Ai\Resolvers\GeminiResolver::class,
    'url' => 'https://generativelanguage.googleapis.com/v1beta',
],
```

### Constructor Changes

**Likelihood Of Impact: Medium** (only if you construct these classes yourself)

All three are normally resolved from the container. If you build them by
hand, for example in tests or factories:

```php
// AiModel — `provider` is now a string and `driver` is required.
// 1.x
new AiModel(provider: Lab::OpenAI, id: 'gpt-5.5');
// 2.0
new AiModel(provider: 'openai', driver: Lab::OpenAI, id: 'gpt-5.5');
// Positional calls break: the order is now
// (provider, driver, id, name, contextWindow, maxOutputTokens, modalities,
//  createdAt, raw, outputModalities, roles).

// ModelRegistry — gained AiManager.
new ModelRegistry(container: $app, cache: $cache, config: $config, ai: $app->make(AiManager::class));

// ConnectorManager — gained AiManager *before* connectorModel.
new ConnectorManager(
    registry: $app->make(ModelRegistry::class),
    ai: $app->make(AiManager::class),
    connectorModel: \App\Models\AiConnector::class,
);
```

Prefer `app(ModelRegistry::class)` and `app(ConnectorManager::class)`. The
service provider wires both and validates `ai-models.connectors.model`.

### Listing Failures Throw laravel/ai Exceptions

**Likelihood Of Impact: Low**

In 1.x, HTTP failures surfaced as Laravel HTTP client exceptions. 2.0 maps
them with the SDK's own failover mapping:

| Failure                                 | 1.x                         | 2.0 |
|-----------------------------------------|-----------------------------|-----|
| Connection refused / timeout            | `ConnectionException`       | `Laravel\Ai\Exceptions\ProviderConnectionException` |
| HTTP 429                                | `RequestException`          | `Laravel\Ai\Exceptions\RateLimitedException` |
| HTTP 402                                | `RequestException`          | `Laravel\Ai\Exceptions\InsufficientCreditsException` |
| 502/503/504/520/522/524 (+ Anthropic 529, Groq 498) | `RequestException` | `Laravel\Ai\Exceptions\ProviderOverloadedException` |
| Any other error status (e.g. 401, 404)  | `RequestException`          | `RequestException` (unchanged) |

The four SDK exceptions extend `Laravel\Ai\Exceptions\AiException` and
implement `FailoverableException`. Their message names the provider, e.g.
`Application rate limited by AI provider [openai].`, and the original HTTP
exception is available via `getPrevious()`:

```php
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;

// 1.x
try {
    $models = AiModels::provider('openai');
} catch (RequestException $e) {
    if ($e->response->status() === 429) { /* back off */ }
}

// 2.0
try {
    $models = AiModels::provider('openai');
} catch (RateLimitedException $e) {
    $retryAfter = $e->getPrevious()?->response?->header('Retry-After');
} catch (AiException $e) {
    report($e);
    $models = collect();
}
```

Exceptions are not cached, so the next call retries the provider. To change
which statuses count as "overloaded" for a lab, set
`overloaded_status_codes` on its resolver entry.

### Cache Keys Changed

**Likelihood Of Impact: Low**

| Cached list            | 1.x key                         | 2.0 key |
|------------------------|---------------------------------|---------|
| Config provider        | `ai-models:openai`              | `ai-models:v2:openai` |
| Database connector     | `ai-models:connector:{id}`      | `ai-models:v2:db-{id}` |
| `driver(..., cacheKey: 'x')` | `ai-models:x`             | (removed) `ai-models:v2:{name}` of the `Ai::build()` provider |

The `v2` segment exists because 1.x entries **cannot** be unserialized by
2.0: their `AiModel`s hold a `Lab` in the now string-typed `$provider`, which
throws a `TypeError`. 2.0 never reads them, so after deploying, every
provider makes one fresh fetch.

- If you clear keys by hand, update the strings, or better, use
  `AiModels::refresh($nameOrLabOrProvider)`.
- `AiModels::refresh('connector:'.$id)` no longer matches anything. Use
  `AiModels::refresh('db-'.$id)`.
- With a TTL (the default is one hour), old entries expire on their own. If
  you cache **forever** (`AI_MODELS_CACHE_TTL=null`), delete the old
  `ai-models:*` keys that don't start with `ai-models:v2:` from the store, or
  flush a dedicated `AI_MODELS_CACHE_STORE`.
- Model roles are **not** cached, so changing `models.*` defaults in
  `config/ai.php` shows up immediately.

### Providers Are Resolved (And Cached) By The SDK

**Likelihood Of Impact: Low** (only if you change `config('ai.providers')` at runtime)

2.0 gets provider instances from laravel/ai's `AiManager` (`Ai::instance($name)`),
which caches each instance by name for the life of the process. That is the
same behaviour as prompting through the SDK. If your application writes into
`config('ai.providers.*')` at runtime and expects the next listing to see the
change, forget the SDK's instance first:

```php
config(['ai.providers.tenant.key' => $newKey]);
app(\Laravel\Ai\AiManager::class)->forgetInstance('tenant');
AiModels::provider('tenant', fresh: true);
```

Better: build such providers with `Ai::build()` and stop mutating config.

### Providers That May Newly Appear

**Likelihood Of Impact: Low**

`AiModels::all()`, `configuredProviders()` and `ai:models` may now list
providers they skipped in 1.x:

- **Entries whose `driver` is a `Lab` case** (`'driver' => Lab::OpenAI`) were
  silently ignored by 1.x. They are now recognised.
- **The SDK's default `openai-compatible` entry** is listed once
  `OPENAI_COMPATIBLE_URL` is set. No key is needed.

If you render "all models" somewhere, check that the extra providers are
welcome. Filter on `$model->provider` or `$model->driver` if not.

### A Base URL Is Now Required

**Likelihood Of Impact: Low**

A resolver now counts as configured only if it has a base URL, either from
the provider's `url` or the resolver entry's `url` fallback, as well as a key
(unless `requires_key` is `false`). Every shipped resolver ships with a
fallback except `openai-compatible`, which by design needs the provider's
`url`. If you registered your own resolver without a `url` fallback and your
providers don't set one either, add one to the resolver entry. Otherwise the
provider is quietly treated as unconfigured and returns no models.

### `ai:models` Output

**Likelihood Of Impact: Low**

- Table columns are now **Provider** (name), **Driver**, **Model ID**,
  **Name**, **Context**, **Roles**, **Created**. In 1.x they were Provider
  (Lab value), Model ID, Name, Context, Created.
- `--json` output follows the new
  [`toArray()` shape](#array--json-output).
- An unreachable, rate-limited or out-of-credit provider prints a one-line
  error and exits with status `1`. In 1.x it threw an uncaught exception. If
  a script relied on the exception's stack trace in output, check the exit
  code instead.

### Updating Your Tests

- **Constructing `AiModel`:** add `driver:` and pass a string `provider:`
  (see [Constructor changes](#constructor-changes)).
- **Asserting on `$model->provider`:** assert the name, or switch the
  assertion to `$model->driver`.
- **Faking failures:** `Http::fake([... => Http::response([], 429)])` now
  leads to `RateLimitedException` rather than `RequestException`. Update
  `expect(...)->toThrow(...)` / `$this->expectException(...)`.
- **Connector tests that asserted on `config('ai.providers.db-…')`:** assert
  on the returned provider instead:

  ```php
  $provider = app(ConnectorManager::class)->provider($connector);

  expect($provider->name())->toBe('db-'.$connector->id)
      ->and($provider->driver())->toBe('openai')
      ->and($provider->providerCredentials())->toBe(['key' => 'sk-test'])
      ->and($provider->additionalConfiguration())->toBe(['url' => 'https://proxy.test/v1']);
  ```

- **Package or Testbench suites:** register `Laravel\Ai\AiServiceProvider` in
  `getPackageProviders()` alongside `AiModelsServiceProvider`, because the
  registry now depends on `AiManager`. Applications get it via package
  discovery.
- **Cache keys in tests:** seed and inspect `ai-models:v2:{name}`.

### New Features Worth Adopting

These need no migration, but they are why 2.0 exists:

```php
use Laravel\Ai\Ai;
use LmSomeco\AiModels\Enums\ModelRole;
use LmSomeco\AiModels\Facades\AiModels;

// The model the SDK would use, for any role
AiModels::default('openai');                              // default text model
AiModels::default('anthropic', ModelRole::CheapestText);
AiModels::default('openai', ModelRole::Embeddings);

// Roles on every model
$model->roles;                         // [ModelRole::Text, ModelRole::SmartestText]
$model->is(ModelRole::CheapestText);

// List models for credentials that never touch config
AiModels::provider(Ai::build([
    'name' => "tenant-{$tenant->id}",
    'driver' => 'anthropic',
    'key' => $tenant->anthropic_key,
]));

// Self-hosted OpenAI-compatible servers (vLLM, LM Studio, LiteLLM, ...)
AiModels::provider(Ai::build([
    'driver' => 'openai-compatible',
    'url' => 'http://llm.internal:8000/v1',
]));
```

See [Usage → Model roles](docs/usage.md#model-roles),
[Usage → Errors](docs/usage.md#errors) and
[Database connectors](docs/database-connectors.md).

### Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `ai:models` lists nothing; `No model resolver registered for provider [openai] (driver: openai).` | Published 1.x `config/ai-models.php` still uses `driver` keys | [Rename to `resolver`](#resolver-config-uses-resolver-instead-of-driver) or re-publish. |
| `Call to undefined method LmSomeco\AiModels\ConnectorManager::configure()` | Removed | Use [`provider()`](#connectormanagerconfigure-was-removed). |
| `Call to undefined method ...ModelRegistry::driver()` / `connector()` | Removed | Use [`provider(Ai::build([...]))` / `ConnectorManager::models()`](#modelregistrydriver-and-connector-were-removed). |
| `Class ... contains 1 abstract method ... Connector::getDriver` | Hand-written `Connector` | [Implement `getDriver(): Lab`](#connectorgetprovider-is-now-getdriver-lab). |
| `Attempt to read property "value" on string` / comparisons with `Lab::X` never match | Reading `$model->provider` as a `Lab` | Use [`$model->driver`](#aimodelprovider-is-now-the-provider-name). |
| `AiModel::__construct(): Argument #2 ($driver) not passed` / `TypeError ... ($provider) must be of type string` | Constructing `AiModel` the 1.x way | [Add `driver:`, pass a string `provider:`](#constructor-changes). |
| `Target [Laravel\Ai\Enums\Lab] is not instantiable while building [App\...\YourResolver].` | Custom resolver still takes `Lab $lab` | [Take `Provider $provider` and `array $config`, or extend `Resolver`](#custom-resolvers). |
| Custom resolver sends no API key / reports unconfigured | It reads `$this->config['key']` | [Use `$this->apiKey()`](#custom-resolvers). |
| `The provider name [db-5] is already taken.` | App still injects `ai.providers.db-5` into config | [Remove the config injection](#connectormanagerconfigure-was-removed). |
| `On-demand provider [db-5] was not built in this process.` | A queued job / Octane request got only the name | [Build in the agent's `provider()`, or dispatch the `Provider` object](#connectormanagerconfigure-was-removed). |
| `TypeError: ... prompt(): Argument #3 ($provider) must be of type ... string` | Passed the `Provider` object to `prompt()` under `strict_types` | Pass `$provider->name()`. |
| `ValueError: "..." is not a valid backing value for enum Laravel\Ai\Enums\Lab` | A connector row's driver isn't a `Lab` value | [Fix the data](#connectorgetprovider-is-now-getdriver-lab). |
| `RateLimitedException` / `ProviderOverloadedException` where you caught `RequestException` | SDK exception mapping | [Catch the SDK exceptions or `AiException`](#listing-failures-throw-laravelai-exceptions). |
| Composer: `laravel/ai ... conflicts with laravel/mcp` | SDK 1.x requires `laravel/mcp` ^1.0 | Upgrade `laravel/mcp` ([before you start](#before-you-start)). |
| Changing a key in `config('ai.providers')` at runtime has no effect | SDK caches provider instances | [`forgetInstance()` or use `Ai::build()`](#providers-are-resolved-and-cached-by-the-sdk). |
| An `openai-compatible` (or `Lab`-driver) provider suddenly shows up in listings | Newly supported | [Filter it out](#providers-that-may-newly-appear) if unwanted. |

### Rolling Back

2.0 needs no database migrations, and the `ai_connectors` table is
unchanged, so rolling back is a dependency change:

```bash
composer require lmsomeco/laravel-ai-models:^1.0.6 laravel/ai:^0.11 -W
```

Also restore a 1.x `config/ai-models.php` if you edited it, and revert any
code changed by this guide. 1.x and 2.x use different cache keys, so neither
version reads the other's entries. Rolling back `laravel/ai` itself is
covered by the SDK's guide (e.g. its conversation `steps` migration).
