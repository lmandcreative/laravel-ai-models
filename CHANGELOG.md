# Changelog

All notable changes to `lmsomeco/laravel-ai-models` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
