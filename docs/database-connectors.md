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

The package migration is only loaded when `ai-models.connectors.enabled` is
true. `ConnectorManager` itself is always bound into the container (the
binding is lazy), so the configured model class is validated whenever it is
first resolved.

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

## Bring your own model

The package never depends on the concrete `AiConnector` class — everything is
typed against the `LmSomeco\AiModels\Contracts\Connector` contract:

| Method | Feeds |
|--------|-------|
| `static findConnector(string $id): ?static` | `ConnectorManager::resolve($id)`; return `null` for "not found" (the manager throws). |
| `static defaultConnector(): ?static` | `ConnectorManager::resolve()` with no id — your "active default" plus fallback ordering. |
| `getConnectorId(): string` | The `db-{id}` provider key and the `connector:{id}` cache key. |
| `getProvider(): string` | The `driver` config entry — a `Lab` enum value, e.g. `"openai"`. |
| `getApiKey(): string` | The `key` config entry (return it decrypted). |
| `getBaseUrl(): ?string` | The `url` config entry; `null` omits it. |

Point `ai-models.connectors.model` at any Eloquent model implementing the
contract:

```php
// config/ai-models.php
'connectors' => [
    'enabled' => env('AI_MODELS_CONNECTORS', false),
    'model' => \App\Models\AiConnector::class,
],
```

There are three ways to satisfy the contract:

1. **Extend `AiConnector`** — e.g. to add relationships. Nothing else to do;
   the parent already implements the contract.
2. **Use the `IsConnector` trait** — for your own model whose columns match
   the standard layout above:

   ```php
   use Illuminate\Database\Eloquent\Model;
   use LmSomeco\AiModels\Concerns\IsConnector;
   use LmSomeco\AiModels\Contracts\Connector;

   class MyConnector extends Model implements Connector
   {
       use IsConnector;
   }
   ```

3. **Implement the methods yourself** — for a fully custom schema (different
   column names, integer keys, your own notion of "default"). Your model owns
   both the data mapping (the getters) and the selection logic (the static
   finders).

If the configured class is not an Eloquent model implementing the contract,
resolving `ConnectorManager` throws an `InvalidArgumentException` naming the
offending class.

## `ConnectorManager`

Resolve the container binding via `app(ConnectorManager::class)` or
constructor injection.

### `resolve(?string $id = null): Connector`

- With `$id`: returns the connector `Connector::findConnector($id)` finds, or
  throws `ModelNotFoundException` if it doesn't exist.
- Without `$id`: returns `Connector::defaultConnector()` — for the shipped
  `AiConnector`, the default active connector, falling back to the first
  active connector by sort order. Throws `ModelNotFoundException` if no
  active connector exists at all.

### `configure(Connector $connector): string`

Registers a runtime `config('ai.providers.db-{id}')` entry for the
connector (`driver`, `key`, `url` — `url` omitted when `getBaseUrl()` is
null) and returns that provider key (`'db-'.$connector->getConnectorId()`)
so you can pass it anywhere a provider name is expected, including straight
into `laravel/ai`:

```php
$providerKey = $connectorManager->configure($connector);

$models = AiModels::provider($providerKey);
Laravel\Ai\Facades\Ai::provider($providerKey)->ask(/* ... */);
```

The config mutation is process-local — it lasts for the current
request/command run only, since credentials are decrypted from the database
rather than stored in `.env`.

### `models(Connector $connector, bool $fresh = false): Collection<int, AiModel>`

Shortcut for listing a connector's models without going through `configure()`
first. Delegates to `ModelRegistry::connector()`.

## `ModelRegistry::connector()` and `driver()`

`ConnectorManager::models()` is a thin wrapper around
`ModelRegistry::connector(Connector $connector, bool $fresh = false)`,
which in turn calls the lower-level `driver()`:

```php
// ModelRegistry::connector()
return $this->driver(
    driver: $connector->getProvider(),
    config: array_filter(['key' => $connector->getApiKey(), 'url' => $connector->getBaseUrl()]),
    cacheKey: 'connector:'.$connector->getConnectorId(),
    fresh: $fresh,
);
```

`driver()` builds a resolver directly from a `Lab` and a credentials array —
no entry in `config('ai.providers')` required. Its cache key format and
`$fresh` semantics are covered in [Caching](caching.md).

You can call `driver()` yourself for credentials that don't come from a
`Connector` model at all:

```php
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Facades\AiModels;

$models = AiModels::driver(
    Lab::from($row->provider),
    ['key' => $row->api_key, 'url' => $row->base_url],
    cacheKey: "connector:{$row->id}",   // omit to skip caching entirely
);
```
