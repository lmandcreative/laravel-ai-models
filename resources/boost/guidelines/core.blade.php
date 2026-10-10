## Laravel AI Models (lmsomeco/laravel-ai-models)

This package lists and caches the currently-available models for each
provider configured in the Laravel AI SDK (`laravel/ai` ^1.0). It resolves
providers through the SDK itself — `config('ai.providers')` entries or
`Ai::build()` instances — and keys resolvers on the `Laravel\Ai\Enums\Lab`
enum. It does not introduce a second place to store provider credentials.

### Conventions

- Resolvers (`LmSomeco\AiModels\Contracts\ProviderResolver` implementations,
  usually extending `LmSomeco\AiModels\Resolvers\Resolver`) are registered in
  `config('ai-models.resolvers')` under a `resolver` key, **keyed by a `Lab`
  enum value** — not by a `config('ai.providers')` array key. A provider is
  matched to a resolver via its driver, so on-demand providers (e.g.
  database connectors) work without any special-casing.
- `LmSomeco\AiModels\Data\AiModel` is a `final readonly` DTO. `$provider` is
  the provider **name** (string, e.g. `openai`, `db-{id}`); `$driver` is the
  `Lab`. Never mutate one — use `withRoles()` or build a new instance.
- Model roles (`LmSomeco\AiModels\Enums\ModelRole`) come from the SDK
  provider's `default*Model()` / `cheapestTextModel()` / `smartestTextModel()`
  methods and are applied after the cache read — never cache them.
- Cache keys take the form `{prefix}:v2:{provider-name}` (default prefix
  `ai-models`). See `ModelRegistry::cacheKey()`.
- Providers without credentials never throw. `ProviderResolver::configured()`
  gates every lookup; an unconfigured provider yields an **empty collection**.
  `provider()` only throws `InvalidArgumentException` for an unknown provider
  name or a driver with *no resolver at all*.
- HTTP failures surface as laravel/ai's failover exceptions
  (`RateLimitedException`, `ProviderOverloadedException`,
  `ProviderConnectionException`, `InsufficientCreditsException`) via the
  SDK's `HandlesFailoverErrors` trait in `Resolver::models()`.
- Every file uses `declare(strict_types=1);` and typed properties/returns.
  Match that in new code.
- Database connectors are optional, gated behind
  `ai-models.connectors.enabled` (`AI_MODELS_CONNECTORS`). ConnectorManager
  works with any Eloquent model implementing
  `LmSomeco\AiModels\Contracts\Connector` (set `ai-models.connectors.model`);
  the shipped `LmSomeco\AiModels\Models\AiConnector` casts its `api_key`
  column `encrypted` — never log or dump it raw, and custom connector models
  must supply their own encrypted-key handling.

### Examples

@verbatim
<code-snippet name="Listing models via the facade" lang="php">
use Laravel\Ai\Ai;
use LmSomeco\AiModels\Enums\ModelRole;
use LmSomeco\AiModels\Facades\AiModels;

// Every configured provider (unconfigured ones are skipped automatically):
$models = AiModels::all();

// One provider — a config/ai.php name, a Lab, or any laravel/ai Provider:
$openAiModels = AiModels::provider('openai');
$tenantModels = AiModels::provider(Ai::build(['driver' => 'anthropic', 'key' => $key]));

// The model the SDK uses for a role:
$cheapest = AiModels::default('openai', ModelRole::CheapestText);

// Only providers with the credentials they need:
$ready = AiModels::configuredProviders(); // Collection<int, string>
</code-snippet>

<code-snippet name="Database connector: resolve, build, use" lang="php">
use LmSomeco\AiModels\ConnectorManager;

$manager = app(ConnectorManager::class);

// Default active connector (falls back to first active by sort order):
$connector = $manager->resolve();

// An on-demand laravel/ai provider named "db-{id}" (no config mutation):
$provider = $manager->provider($connector);

// List its models (cached under "ai-models:v2:db-{id}"):
$models = $manager->models($connector);

// Prompt with it — return it from an agent's provider() method, or:
$response = (new SupportAgent)->prompt('...', provider: $provider->name());
</code-snippet>

<code-snippet name="Registering a custom provider resolver" lang="php">
// config/ai-models.php
use Laravel\Ai\Enums\Lab;

'resolvers' => [
    // ...

    Lab::Gemini->value => [
        'resolver' => \App\Ai\Resolvers\GeminiResolver::class,
        'url' => 'https://generativelanguage.googleapis.com/v1beta', // fallback only
    ],
],

// app/Ai/Resolvers/GeminiResolver.php — extend OpenAiCompatibleResolver
// when the endpoint is OpenAI-shaped, or extend Resolver and implement
// fetch() (see AnthropicResolver) when it isn't.
</code-snippet>
@endverbatim
