# Database connectors

By default every provider comes from `config/ai.php`. If you need
per-tenant, admin-managed, or otherwise runtime-editable provider
configuration (e.g. letting users bring their own API key), this package
optionally ships an Eloquent model and a small manager class for that.

## Enabling

```bash
# .env
AI_MODELS_CONNECTORS=true
```

```bash
php artisan vendor:publish --tag=ai-models-migrations
php artisan migrate
```

`ConnectorManager` is only bound into the container (and the migration only
loaded) when `ai-models.connectors.enabled` is true.

## The `ai_connectors` table

| Column        | Type                | Notes |
|----------------|---------------------|-------|
| `id`             | `uuid`, primary key  | `HasUuids`. |
| `name`           | `string`             | Human-readable label. |
| `provider`       | `string`             | A `Lab` enum value, e.g. `"openai"`, `"anthropic"`. |
| `api_key`        | `text`               | Cast `encrypted` — encrypted at rest via `APP_KEY`. |
| `model`          | `string`, nullable   | Optional default model ID for your own use. |
| `base_url`       | `string`, nullable   | Overrides the provider's default API base URL. |
| `settings`       | `json`, nullable     | Cast `array`. Extra driver-specific settings. |
| `is_active`      | `boolean`, default `true`  | |
| `is_default`     | `boolean`, default `false` | |
| `sort_order`     | `unsigned int`, default `0` | Used to break ties when there's no default. |

`AiConnector` also defines three query scopes: `active()` (`is_active = true`),
`default()` (`is_default = true`) and `ordered()`
(`orderBy('sort_order')->orderBy('created_at')`).

To use your own Eloquent class (e.g. to add relationships), extend
`AiConnector` and point `ai-models.connectors.model` at it:

```php
// config/ai-models.php
'connectors' => [
    'enabled' => env('AI_MODELS_CONNECTORS', false),
    'model' => \App\Models\AiConnector::class,
],
```

## `ConnectorManager`

Resolve the container binding via `app(ConnectorManager::class)` or
constructor injection.

### `resolve(?string $id = null): AiConnector`

- With `$id`: returns that connector by UUID, or throws
  `ModelNotFoundException` if it doesn't exist.
- Without `$id`: returns the default active connector
  (`active()->default()->ordered()->first()`), falling back to the first
  active connector by sort order. Throws `ModelNotFoundException` if no
  active connector exists at all.

### `configure(AiConnector $connector): string`

Registers a runtime `config('ai.providers.db-{uuid}')` entry for the
connector (`driver`, `key`, `url` — `url` omitted when `base_url` is null)
and returns that provider key (`"db-{$connector->id}"`) so you can pass it
anywhere a provider name is expected, including straight into `laravel/ai`:

```php
$providerKey = $connectorManager->configure($connector);

$models = AiModels::provider($providerKey);
Laravel\Ai\Facades\Ai::provider($providerKey)->ask(/* ... */);
```

The config mutation is process-local — it lasts for the current
request/command run only, since credentials are decrypted from the database
rather than stored in `.env`.

### `models(AiConnector $connector, bool $fresh = false): Collection<int, AiModel>`

Shortcut for listing a connector's models without going through `configure()`
first. Delegates to `ModelRegistry::connector()`.

## `ModelRegistry::connector()` and `driver()`

`ConnectorManager::models()` is a thin wrapper around
`ModelRegistry::connector(AiConnector $connector, bool $fresh = false)`,
which in turn calls the lower-level `driver()`:

```php
// ModelRegistry::connector()
return $this->driver(
    driver: $connector->provider,
    config: array_filter(['key' => $connector->api_key, 'url' => $connector->base_url]),
    cacheKey: 'connector:'.$connector->id,
    fresh: $fresh,
);
```

`driver()` builds a resolver directly from a `Lab` and a credentials array —
no entry in `config('ai.providers')` required. Its cache key format and
`$fresh` semantics are covered in [Caching](caching.md).

You can call `driver()` yourself for connectors that don't come from the
`AiConnector` model at all:

```php
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Facades\AiModels;

$models = AiModels::driver(
    Lab::from($connector->provider),
    ['key' => $connector->api_key, 'url' => $connector->base_url],
    cacheKey: "connector:{$connector->id}",   // omit to skip caching entirely
);
```
