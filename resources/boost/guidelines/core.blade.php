## Laravel AI Models (lmsomeco/laravel-ai-models)

This package lists and caches the currently-available models for each
provider configured in the Laravel AI SDK (`laravel/ai`). It piggybacks on
the SDK's own `config('ai.php')` and `Laravel\Ai\Enums\Lab` enum — it does
not introduce a second place to store provider credentials.

### Conventions

- Resolvers (`LmSomeco\AiModels\Contracts\ProviderResolver` implementations)
  are registered in `config('ai-models.resolvers')`, **keyed by a `Lab`
  enum value** — not by a `config('ai.providers')` array key. A provider
  entry is matched to a resolver via its own `driver` field, so
  runtime-registered providers (e.g. database connectors) work without any
  special-casing.
- `LmSomeco\AiModels\Data\AiModel` is a `final readonly` DTO. Never mutate
  one after construction — build a new instance instead.
- Cache keys always take the form `{prefix}:{name}` (default prefix
  `ai-models`), e.g. `ai-models:openai` or `ai-models:connector:{uuid}`. See
  `ModelRegistry::cacheKey()`.
- Providers without credentials never throw. `ProviderResolver::configured()`
  gates every lookup; an unconfigured provider yields an **empty collection**,
  not an exception. `provider()`/`driver()` only throw
  `InvalidArgumentException` for a provider/driver that has *no resolver at
  all* — never for one that's simply missing a key.
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
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Facades\AiModels;

// Every configured provider (empty collections are skipped automatically):
$models = AiModels::all();

// One provider — accepts a Lab or its string value:
$openAiModels = AiModels::provider(Lab::OpenAI);

// Only providers with the credentials they need:
$ready = AiModels::configuredProviders(); // Collection<int, string>
</code-snippet>

<code-snippet name="Database connector: resolve, configure, use" lang="php">
use LmSomeco\AiModels\ConnectorManager;

$manager = app(ConnectorManager::class);

// Default active connector (falls back to first active by sort order):
$connector = $manager->resolve();

// List its models directly (cached under "ai-models:connector:{uuid}"):
$models = $manager->models($connector);

// Or inject it into config('ai.providers') and hand the key to laravel/ai:
$providerKey = $manager->configure($connector); // "db-{uuid}"
$response = \Laravel\Ai\Facades\Ai::provider($providerKey)->ask('...');
</code-snippet>

<code-snippet name="Registering a custom provider resolver" lang="php">
// config/ai-models.php
use Laravel\Ai\Enums\Lab;

'resolvers' => [
    // ...

    Lab::Gemini->value => [
        'driver' => \App\Ai\Resolvers\GeminiResolver::class,
    ],
],

// app/Ai/Resolvers/GeminiResolver.php — extend OpenAiCompatibleResolver
// when the endpoint is OpenAI-shaped, or implement ProviderResolver
// directly (see AnthropicResolver) when it isn't.
</code-snippet>
@endverbatim
