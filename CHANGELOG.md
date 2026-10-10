# Changelog

All notable changes to `lmsomeco/laravel-ai-models` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

Built on `laravel/ai` 1.x. See [UPGRADE.md](UPGRADE.md) for migration steps.

### Added

- `Enums\ModelRole` and `AiModel::$roles` / `AiModel::is()`: every listed model
  is tagged with the roles the SDK provider assigns it — default, cheapest and
  smartest text model, and the default image, embeddings, audio,
  transcription, reranking and classification models. Roles honour
  `models.*` overrides and are applied after the cache read.
- `AiModels::default($provider, ModelRole $role = ModelRole::Text)`.
- `AiModels::provider()` accepts any laravel/ai `Provider` instance, including
  on-demand providers from `Ai::build()`.
- `ConnectorManager::provider()`: builds a serializable, queue-safe on-demand
  provider (`db-{id}`) with `Ai::build()`.
- `Resolvers\Resolver` abstract base class for HTTP resolvers.
- Resolver for the SDK's `openai-compatible` driver (`Lab::OpenAICompatible`);
  the API key is optional.
- Listing failures are rethrown as laravel/ai's failover exceptions
  (`RateLimitedException`, `ProviderOverloadedException`,
  `ProviderConnectionException`, `InsufficientCreditsException`), with
  per-lab overloaded status codes (Anthropic 529, Groq 498) configurable via
  `overloaded_status_codes`.
- `AiModel::$driver` and the `ai:models` table's Driver and Roles columns.
- `ai:models` reports an unreachable or rate-limited provider as a one-line
  error (failure exit code) instead of an uncaught exception.

### Changed

- **Breaking:** requires `laravel/ai` ^1.0.1; 0.x is no longer supported.
- **Breaking:** `AiModel::$provider` is now the provider name (`string`, e.g.
  `openai` or `db-5`); the `Lab` moved to the new `AiModel::$driver`.
- **Breaking:** `ProviderResolver::provider()` returns the laravel/ai
  `Provider` instance; resolvers are constructed with
  `(HttpFactory $http, Provider $provider, array $config)`.
- **Breaking:** `Contracts\Connector::getProvider(): string` is now
  `getDriver(): Lab`.
- **Breaking:** resolver entries in `config/ai-models.php` name their class
  under `resolver` (was `driver`).
- **Breaking:** cache keys are now `{prefix}:v2:{provider-name}`; connector
  lists are cached under `db-{id}` (was `connector:{id}`).
- Providers are resolved through laravel/ai's `AiManager`, so a
  `config/ai.php` entry whose `driver` is a `Lab` case (not a string) is now
  picked up, and providers without a resolver are never instantiated.
- Default base URLs for OpenAI and Anthropic moved into the resolver config.

### Removed

- **Breaking:** `ConnectorManager::configure()` (use `provider()`; nothing is
  written to `config('ai.providers')` any more).
- **Breaking:** `ModelRegistry::driver()` (use `provider(Ai::build([...]))`)
  and `ModelRegistry::connector()` (use `ConnectorManager::models()`).

## [1.0.3] - 2026-08-19

### Added

- `AiModel::$outputModalities`, plus `accepts()` and `generates()` helpers, so
  applications can filter returned collections by input/output capability.
- OpenRouter output modality mapping from `architecture.output_modalities`.
- Anthropic modality, context-window, and output-token mapping from its current
  model-list response, with documented text/image defaults for older payloads.
- `OpenAiResolver`, which enriches official OpenAI model families with their
  documented modalities because OpenAI's model-list response omits them.

### Changed

- Cached model collections from older releases are refetched when they do not
  contain the newly normalized output modality property.

## [1.0.2] - 2026-08-07

### Added

- `Contracts\Connector` — the interface `ConnectorManager` and
  `ModelRegistry::connector()` now depend on, so applications can bring their
  own connector Eloquent model (any table, columns or key type) via
  `ai-models.connectors.model`: four getters (`getConnectorId()`,
  `getProvider()`, `getApiKey()`, `getBaseUrl()`) plus two static finders
  (`findConnector()`, `defaultConnector()`) that own record selection.
- `Concerns\IsConnector` — trait implementing the whole contract for models
  using the standard `ai_connectors` column layout; `AiConnector` now uses it.
- The service provider validates `ai-models.connectors.model` when
  `ConnectorManager` is first resolved and throws an informative
  `InvalidArgumentException` for classes that are not Eloquent models
  implementing the contract.

### Changed

- `ConnectorManager::resolve()/configure()/models()` and
  `ModelRegistry::connector()` are now typed against `Contracts\Connector`
  instead of the concrete `AiConnector`. Subclasses overriding these methods
  must widen their `AiConnector` parameter types to `Connector`; plain callers
  are unaffected.
- The `ConnectorManager` singleton is registered unconditionally (the binding
  is lazy). Previously it was gated on `ai-models.connectors.enabled`, which
  let the container silently fall back to reflection auto-wiring — ignoring
  the configured model class — whenever the flag was read too late in the
  boot order. Migrations remain gated on the flag.

### Fixed

- Removed docblocks claiming the connector model could be swapped by binding
  `AiConnector` in the container — `ai-models.connectors.model` is, and always
  was, the only swap mechanism.
- `ModelRegistry` no longer throws a `TypeError` when a cache entry holds a
  payload it cannot use — e.g. objects serialized by an older deploy (or
  another app sharing the cache store) whose classes can no longer be loaded
  and unserialize as `__PHP_Incomplete_Class`. Such entries are now discarded
  and the model list is refetched from the provider.

## [1.0.1] - 2026-08-06

### Fixed

- Widened the `laravel/ai` constraint from `^0.7` to `^0.7|^0.8|^0.9|^0.10`.
  Because Composer treats `^0.7` as `>=0.7.0 <0.8.0`, v1.0.0 could not be
  installed alongside `laravel/ai` 0.8, 0.9 or 0.10. The package's only
  coupling to the SDK is the `Laravel\Ai\Enums\Lab` enum and the
  `config('ai.providers')` entry shape (`driver`/`key`/`url`), both of which
  are unchanged across that range — every 0.7.0–0.10.2 release is verified in
  CI.

## [1.0.0] - 2026-08-06

### Added

- `ModelRegistry` — lists and caches available models per provider configured
  in `config/ai.php`, keyed on `laravel/ai`'s `Lab` enum.
- `ai:models` Artisan command with `--refresh` and `--json` options.
- `AiModels` facade (`all()`, `provider()`, `providers()`, `configuredProviders()`,
  `driver()`, `refresh()`).
- Resolvers for OpenAI, Groq, Mistral, DeepSeek, xAI (`OpenAiCompatibleResolver`),
  OpenRouter (`OpenRouterResolver`), and Anthropic (`AnthropicResolver`).
- Optional database-backed connectors (`AiConnector` Eloquent model,
  `ConnectorManager`) behind `AI_MODELS_CONNECTORS`, with an encrypted `api_key`
  column and a publishable migration.
- Per-provider response caching with configurable store, TTL and prefix.
