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
| `provider`       | `string`             | A `Lab` enum value, e.g. `"openai"`, `"anthropic"` (`IsConnector::getDriver()` turns it into the `Lab`). |
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
| `getConnectorId(): string` | The `db-{id}` provider name (also its model cache key). |
| `getDriver(): Lab` | The provider's `driver`, e.g. `Lab::OpenAI`. |
| `getApiKey(): string` | The provider's `key` (return it decrypted). |
| `getBaseUrl(): ?string` | The provider's `url`; `null` omits it. |

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

### `provider(Connector $connector): Provider`

Builds an on-demand laravel/ai provider for the connector with `Ai::build()`,
named `db-{id}` (`driver`, `key` and `url` — `url` omitted when
`getBaseUrl()` is null). Nothing is written to `config('ai.providers')`.

```php
$provider = $connectorManager->provider($connector);

$provider->name();                    // 'db-9c1e...'
$models = AiModels::provider($provider);
```

To prompt with it, either return it from your agent's `provider()` method —
the SDK's recommended home for on-demand providers — or pass its name while
it's registered for the current operation:

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Promptable;
use LmSomeco\AiModels\ConnectorManager;

class SupportAgent implements Agent
{
    use Promptable;

    public function __construct(public string $connectorId) {}

    public function provider(): Provider
    {
        $manager = app(ConnectorManager::class);

        return $manager->provider($manager->resolve($this->connectorId));
    }

    // instructions(), ...
}

// or, in the same request/job that built it:
(new SupportAgent($id))->prompt('...', provider: $provider->name());
```

The provider serializes with its configuration **encrypted** (via `APP_KEY`),
so it is safe to hand to queued work. laravel/ai flushes on-demand providers
between queue jobs and Octane operations, which is why building it inside the
agent's `provider()` method is the most robust option.

### `models(Connector $connector, bool $fresh = false): Collection<int, AiModel>`

Lists the connector's models — shorthand for
`AiModels::provider($connectorManager->provider($connector), $fresh)`.
Results are cached under the `db-{id}` provider name; see
[Caching](caching.md).

## Credentials that don't come from a `Connector`

Any provider you build yourself works the same way — no model required:

```php
use Laravel\Ai\Ai;
use LmSomeco\AiModels\Facades\AiModels;

$models = AiModels::provider(Ai::build([
    'name' => "tenant-{$tenant->id}",
    'driver' => $tenant->ai_driver,     // a Lab value
    'key' => $tenant->ai_key,
    'url' => $tenant->ai_url,
]));
```
