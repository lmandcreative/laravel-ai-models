---
name: ai-models-development
description: Use when adding provider resolvers, working with the model registry/cache, or wiring database connectors in lmsomeco/laravel-ai-models.
---

# AI Models package development

This skill covers working *on* `lmsomeco/laravel-ai-models` itself: adding a
provider resolver, changing caching/registry behavior, or extending the
database connector system.

## Architecture map

```
ModelRegistry              orchestrates everything; resolvable via the
  |                        AiModels facade or DI. Depends on laravel/ai's
  |                        AiManager.
  |-- providers()          every config('ai.providers') entry whose driver has
  |                        a resolver, built via AiManager::instance().
  |-- provider()/all()     look up / cache models for a provider name, Lab or
  |                        any laravel/ai Provider instance (Ai::build()).
  |-- default()            the listed model holding a ModelRole.
  |   |
  |   `-- makeResolver()   picks config('ai-models.resolvers.{driver}.resolver')
  |                        and container->make()s it with the Provider and the
  |                        remaining entry keys as defaults.
  |
Enums\ModelRole            default/cheapest/smartest text, image, embeddings,
                            audio, transcription, reranking, classification —
                            read from the SDK provider, applied after the cache.

ProviderResolver (interface)
  `-- Resolver (abstract)        reads key/url from the Provider, wraps fetch()
        |                        in the SDK's HandlesFailoverErrors.
        |-- OpenAiCompatibleResolver   Groq, Mistral, DeepSeek, xAI,
        |     |                        openai-compatible
        |     |-- OpenAiResolver       enriches documented OpenAI modalities
        |     `-- OpenRouterResolver   maps rich API metadata
        `-- AnthropicResolver          bespoke: x-api-key header, pagination

ConnectorManager            wraps any Eloquent model implementing
  |                         Contracts\Connector (default: Models\AiConnector;
  |                         Concerns\IsConnector supplies the contract for
  |                         standard columns). Migrations gated behind
  |                         ai-models.connectors.enabled.
  |-- resolve()             Connector::findConnector() / defaultConnector().
  |-- provider()            Ai::build() an on-demand provider named db-{id}.
  `-- models()              -> ModelRegistry::provider(provider()).

Console\ListModelsCommand   `php artisan ai:models` — thin wrapper over
                             ModelRegistry::provider()/all().

Facades\AiModels             static proxy to the ModelRegistry singleton.
```

Everything is built through the container. Resolvers receive
`Provider $provider` and `array $config` (resolver defaults) via constructor
injection — add other typed dependencies freely.

## Adding a new provider resolver

1. Check whether the provider's model-listing endpoint is OpenAI-shaped
   (`GET /models` → `{"data": [...]}`, Bearer auth). If so, extend
   `LmSomeco\AiModels\Resolvers\OpenAiCompatibleResolver` and override only
   what differs — usually just `mapModel()` for extra fields, or
   `modelsPath()`/`request()` for auth quirks. `OpenRouterResolver` is the
   reference example.
2. Otherwise, extend `LmSomeco\AiModels\Resolvers\Resolver` and implement
   `fetch(): Collection`, building models with `newModel(...)`.
   `AnthropicResolver` is the reference example (custom headers, pagination).
3. Map the resolver to a `Lab` value in `config/ai-models.php`'s
   `resolvers` array — the array key must be `Lab::X->value`, not a
   `config('ai.providers')` name:
   ```php
   Lab::Gemini->value => [
       'resolver' => \LmSomeco\AiModels\Resolvers\GeminiResolver::class,
       'url' => 'https://generativelanguage.googleapis.com/v1beta', // fallback only
   ],
   ```
   Add `requires_key => false` if listing works without a key, and
   `overloaded_status_codes` if the lab has its own transient statuses.
4. `configured()` must return `false` (not throw) when required credentials
   are missing — the registry treats that as "no models", not an error.
5. `fetch()` must return `Collection<int, AiModel>`, sorted by `id` for
   consistency with the existing resolvers, and should call `->throw()` on
   the HTTP response so upstream failures surface as the SDK's failover
   exceptions rather than silently returning nothing.
6. Don't set model roles in a resolver — the registry applies them.
7. Update the provider table in `README.md` and the "not yet implemented"
   list in `docs/extending.md` and `config/ai-models.php`'s comment block.
8. Add a CHANGELOG `[Unreleased]` entry.

## Testing patterns

Pest 3 on Orchestra Testbench. Run everything with `composer test`
(`vendor/bin/pest`); Windows resolves the `vendor/bin/pest.bat` shim via
Composer automatically.

- **Resolver tests** (`tests/Unit/Resolvers/*Test.php`): fake HTTP with
  `Illuminate\Support\Facades\Http::fake([...])`, build the SDK provider with
  the `sdkProvider(Lab::X, ['key' => ..., 'url' => ...])` helper from
  `tests/Pest.php`, and assert on the returned `AiModel` fields. No database
  involved — these extend the plain `LmSomeco\AiModels\Tests\TestCase`,
  which registers laravel/ai's `AiServiceProvider`.
- **Registry/command tests** (`tests/Feature/*Test.php`): also extend
  `TestCase`, which resets `config('ai.providers')` to `[]` in
  `defineEnvironment()` so each test registers exactly the providers it
  needs.
- **Connector tests** (`tests/Database/*Test.php`): extend
  `LmSomeco\AiModels\Tests\DatabaseTestCase`, which layers an in-memory
  SQLite connection and `ai-models.connectors.enabled = true` on top of
  `TestCase`, and auto-loads the package migration.
- Pest suite wiring lives in `tests/Pest.php` (`uses(TestCase::class)->in('Feature', 'Unit')`,
  `uses(DatabaseTestCase::class)->in('Database')`) — new test files just need
  to land in the right directory.

Before committing, run the full gate: `composer check` (lint, then phpstan
`analyse`, then `test`). `composer lint:fix` auto-fixes Pint style issues.

## Config/env reference

| Config key                     | Env var                  | Default |
|----------------------------------|---------------------------|---------|
| `ai-models.cache.store`           | `AI_MODELS_CACHE_STORE`    | `null` (app default store) |
| `ai-models.cache.ttl`             | `AI_MODELS_CACHE_TTL`      | `3600` (`null` = forever) |
| `ai-models.cache.prefix`          | —                          | `"ai-models"` |
| `ai-models.timeout`               | `AI_MODELS_TIMEOUT`        | `15` |
| `ai-models.connectors.enabled`    | `AI_MODELS_CONNECTORS`     | `false` |
| `ai-models.connectors.model`      | —                          | `LmSomeco\AiModels\Models\AiConnector::class` — any Eloquent model implementing `Contracts\Connector` |
| `ai-models.resolvers`             | —                          | see `config/ai-models.php` |

Full behavioral reference: `docs/usage.md`, `docs/caching.md`,
`docs/database-connectors.md`, `docs/extending.md`.
