# Extending

## The `ProviderResolver` contract

Every provider is backed by a class implementing
`LmSomeco\AiModels\Contracts\ProviderResolver`:

```php
interface ProviderResolver
{
    public function provider(): Lab;

    public function configured(): bool;

    /** @return Collection<int, AiModel> */
    public function models(): Collection;
}
```

Resolvers are built through the container
(`ModelRegistry::makeResolver()` calls `$container->make(...)`), so
constructor arguments beyond the two described below can be any container
dependency.

## The `Connector` contract

Database connectors are typed against `LmSomeco\AiModels\Contracts\Connector`
rather than the shipped `AiConnector` model, so any Eloquent model can act as
a connector:

```php
interface Connector
{
    public static function findConnector(string $id): ?static;

    public static function defaultConnector(): ?static;

    public function getConnectorId(): string;

    public function getProvider(): string;

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

## Registering a resolver

Map a `Lab` case to your resolver class in `config/ai-models.php`:

```php
use Laravel\Ai\Enums\Lab;

'resolvers' => [
    // ...existing entries...

    Lab::Gemini->value => [
        'driver' => \App\Ai\Resolvers\GeminiResolver::class,
    ],
],
```

The array key is a `Lab` **value**, not a `config('ai.providers')` provider
name — resolvers are matched by the provider entry's `driver`, so any
provider (including runtime-registered ones, e.g. `db-{uuid}` connectors)
whose `driver` resolves to a mapped `Lab` picks up the resolver
automatically, whatever the provider is named.

## Config merge semantics

Every resolver is constructed with `Lab $lab` and an `array $config`
built by `ModelRegistry::makeResolver()`:

1. Start from the resolver's own entry in `ai-models.resolvers` (e.g. a
   fallback `url`, or Anthropic's `version`).
2. Merge the matching `config('ai.providers.{name}')` entry **on top** — so
   `key`/`url`/anything else the application declares wins over the
   resolver's fallback.
3. Fill in `timeout` from `AI_MODELS_TIMEOUT` if the merged config didn't
   already set one.

In short: **your app's `config/ai.php` entry always wins**; the resolver
config in `config/ai-models.php` only supplies defaults for what the app
entry doesn't set.

## Extending `OpenAiCompatibleResolver`

If your provider exposes an OpenAI-style `GET /models` endpoint returning
`{ "data": [...] }` with Bearer auth, extend
`LmSomeco\AiModels\Resolvers\OpenAiCompatibleResolver` instead of
implementing the interface from scratch. Override just what differs:

| Method                              | Default                          | Override to change... |
|---------------------------------------|-----------------------------------|-------------------------|
| `apiKey(): ?string`                    | `$this->config['key']`            | Where the key comes from. |
| `baseUrl(): string`                    | `$this->config['url']`, trimmed   | The base URL. |
| `modelsPath(): string`                 | `'/models'`                       | The listing endpoint path. |
| `request(): PendingRequest`             | Bearer-token JSON request          | Headers/auth scheme. |
| `mapModel(array $model): ?AiModel`      | Maps `id` only                     | Extra fields your provider returns (context length, pricing, modalities, ...). Return `null` to skip a malformed entry. |

`OpenRouterResolver` is a good example: it overrides `configured()` (the
listing endpoint is public, no key required) and `mapModel()` (to pull in
`context_length`, `top_provider.max_completion_tokens` and
`architecture.input_modalities`).

If the provider's shape differs more fundamentally (auth headers, pagination,
response envelope), implement `ProviderResolver` directly — see
`AnthropicResolver` for an example with `x-api-key`/`anthropic-version`
headers and `has_more`/`after_id` pagination.

## Providers without a resolver yet

These are mapped to a `Lab` in `laravel/ai` but have no resolver in this
package yet — each needs a bespoke implementation because its model listing
differs from the OpenAI convention. Contributions welcome:

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

See [CONTRIBUTING.md](../CONTRIBUTING.md) for how to submit one.
