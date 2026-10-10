# Extending

## The `ProviderResolver` contract

Every driver is backed by a class implementing
`LmSomeco\AiModels\Contracts\ProviderResolver`:

```php
use Laravel\Ai\Contracts\Providers\Provider;

interface ProviderResolver
{
    /** The laravel/ai provider instance this resolver lists models for. */
    public function provider(): Provider;

    public function configured(): bool;

    /** @return Collection<int, AiModel> */
    public function models(): Collection;
}
```

Resolvers are built through the container
(`ModelRegistry::makeResolver()` calls `$container->make(...)` with
`provider` and `config` named arguments), so any other constructor arguments
can be container dependencies.

## The `Resolver` base class

For anything that lists models over HTTP, extend
`LmSomeco\AiModels\Resolvers\Resolver` and implement one method:

```php
use Illuminate\Support\Collection;
use LmSomeco\AiModels\Resolvers\Resolver;

class GeminiResolver extends Resolver
{
    protected function fetch(): Collection
    {
        $response = $this->request()
            ->get('/models', ['key' => $this->apiKey()])
            ->throw();

        return collect($response->json('models', []))
            ->map(fn (array $model) => $this->newModel(
                id: str($model['name'])->after('models/')->toString(),
                name: $model['displayName'] ?? null,
                contextWindow: $model['inputTokenLimit'] ?? null,
                maxOutputTokens: $model['outputTokenLimit'] ?? null,
                raw: $model,
            ))
            ->sortBy('id')
            ->values();
    }
}
```

The base class provides:

| Member                         | Does |
|--------------------------------|------|
| `__construct(HttpFactory $http, Provider $provider, array $config = [])` | `$config` is the resolver's entry from `config/ai-models.php` (minus `resolver`) plus `timeout`. |
| `configured(): bool`           | `true` when there's a base URL and — unless `requires_key` is `false` — an API key. |
| `models(): Collection`         | `final`. Calls `fetch()` and rethrows HTTP failures as laravel/ai's failover exceptions (see [Usage → Errors](usage.md#errors)). |
| `name(): string`               | The provider name (`$provider->name()`). |
| `driver(): Lab`                | The provider's driver as a `Lab`. |
| `apiKey(): ?string`            | `$provider->providerCredentials()['key']`, or `null`. |
| `baseUrl(): string`            | The provider's `url`, else the resolver's `url` fallback, right-trimmed of `/`. |
| `request(): PendingRequest`    | JSON request against `baseUrl()` with the configured timeout. |
| `newModel(...)`                | Builds an `AiModel` with `provider` and `driver` filled in. |
| `overloadedStatusCodes(): array` | From `overloaded_status_codes`, else the SDK's default transient 5xx set. |

Model [roles](usage.md#model-roles) are added by the registry — resolvers
don't need to set them.

## Registering a resolver

Map a `Lab` case to your resolver class in `config/ai-models.php`:

```php
use Laravel\Ai\Enums\Lab;

'resolvers' => [
    // ...existing entries...

    Lab::Gemini->value => [
        'resolver' => \App\Ai\Resolvers\GeminiResolver::class,
        'url' => 'https://generativelanguage.googleapis.com/v1beta',
    ],
],
```

The array key is a `Lab` **value** — resolvers are matched by the provider's
driver, so every provider using that driver picks up the resolver
automatically, whatever it is named: `config/ai.php` entries, `db-{id}`
connectors and anything else built with `Ai::build()`.

Every key besides `resolver` is a default passed to the resolver as
`$config`:

| Key                       | Meaning |
|---------------------------|---------|
| `url`                     | Fallback base URL when the provider doesn't set one. |
| `requires_key`            | `false` to treat a provider with no API key as configured (default `true`). |
| `overloaded_status_codes` | HTTP statuses rethrown as `ProviderOverloadedException`. |
| `timeout`                 | Per-resolver HTTP timeout; defaults to `AI_MODELS_TIMEOUT`. |
| anything else             | Free for your resolver (e.g. Anthropic's `version`). |

**The provider's own configuration always wins**: credentials and `url` are
read from the laravel/ai provider instance, and the resolver entry only
supplies defaults for what the provider doesn't set.

## Extending `OpenAiCompatibleResolver`

If your provider exposes an OpenAI-style `GET /models` endpoint returning
`{ "data": [...] }` with Bearer auth, extend
`LmSomeco\AiModels\Resolvers\OpenAiCompatibleResolver` and override just what
differs:

| Method                              | Default                          | Override to change... |
|---------------------------------------|-----------------------------------|-------------------------|
| `modelsPath(): string`                 | `'/models'`                       | The listing endpoint path. |
| `request(): PendingRequest`             | Bearer-token JSON request (token omitted when there's no key) | Headers/auth scheme. |
| `mapModel(array $model): ?AiModel`      | Maps `id` and `created`            | Extra fields your provider returns (context length, pricing, modalities, ...). Return `null` to skip a malformed entry. |

`OpenRouterResolver` is a good example: it overrides `configured()` (the
listing endpoint is public, no key required) and `mapModel()` (to pull in
`context_length`, `top_provider.max_completion_tokens` and
`architecture.input_modalities` / `architecture.output_modalities`).

`OpenAiResolver` is the OpenAI-specific subclass. Since OpenAI's `/models`
payload has no capability fields, it enriches official model families with
their documented input and output modalities while leaving unknown IDs empty.
Other OpenAI-compatible providers continue to use the base resolver without
these OpenAI-specific assumptions.

If the provider's shape differs more fundamentally (auth headers, pagination,
response envelope), extend `Resolver` directly — see `AnthropicResolver` for
an example with `x-api-key`/`anthropic-version` headers and
`has_more`/`after_id` pagination.

## The `Connector` contract

Database connectors are typed against `LmSomeco\AiModels\Contracts\Connector`
rather than the shipped `AiConnector` model, so any Eloquent model can act as
a connector:

```php
use Laravel\Ai\Enums\Lab;

interface Connector
{
    public static function findConnector(string $id): ?static;

    public static function defaultConnector(): ?static;

    public function getConnectorId(): string;

    public function getDriver(): Lab;

    public function getApiKey(): string;

    public function getBaseUrl(): ?string;
}
```

When your model's columns match the standard `ai_connectors` layout, the
whole contract is one trait away:

```php
class MyConnector extends Model implements Connector
{
    use \LmSomeco\AiModels\Concerns\IsConnector;
}
```

See [Database connectors](database-connectors.md) for the full
bring-your-own-model guide.

## Providers without a resolver yet

These are drivers in `laravel/ai` with no resolver in this package yet — each
needs a bespoke implementation because its model listing differs from the
OpenAI convention. Contributions welcome:

| Provider          | Notes |
|---------------------|-------|
| `Gemini`             | Key passed in the query string; model IDs prefixed with `models/`. |
| `Ollama`             | `GET /api/tags`, a different JSON shape entirely. |
| `Azure`              | Deployment-based, `api-version` query parameter. |
| `Cohere`             | `GET /v1/models`, paginated. |
| `ElevenLabs`         | `GET /v1/models`, `xi-api-key` header (`Lab` value: `eleven`). |
| `Bedrock`            | AWS SigV4 / bearer-token auth. |
| `Jina`               | No list endpoint — static catalog. |
| `VoyageAI`           | No list endpoint — static catalog. |
| `TypeSafe`           | Classification only, no list endpoint. |

See [CONTRIBUTING.md](../CONTRIBUTING.md) for how to submit one.
