# Changelog

All notable changes to `lmsomeco/laravel-ai-models` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - Unreleased

**2.0 is built on `laravel/ai` 1.x.** It drops support for the 0.x SDK and
uses the 1.x APIs throughout. Providers are resolved through the SDK's own
`AiManager`, database connectors become on-demand providers built with
`Ai::build()`, models are tagged with the roles the SDK assigns them, and
listing failures raise the SDK's failover exceptions.

Most applications that only call `AiModels::all()` / `AiModels::provider()`
and read `$model->id` need two changes: read `$model->driver` where they read
`$model->provider` as a `Lab`, and re-publish (or edit) a published
`config/ai-models.php`. Everything else is covered step by step in
[UPGRADE.md](UPGRADE.md); the [release notes](docs/releases/v2.0.0.md)
summarise the highlights.

### Added

#### Laravel AI SDK 1.x integration

- `ModelRegistry::provider()` / `AiModels::provider()` accept any
  `Laravel\Ai\Contracts\Providers\Provider` instance, alongside a
  `config/ai.php` provider name or a `Lab`. That includes `Ai::instance('openai')`
  and providers built on demand with `Ai::build([...])`, so credentials that
  never touch config (per-tenant keys, admin-managed keys, BYOK) can be listed
  directly. Results are cached under the provider's `name()`.
- `ConnectorManager::provider(Connector $connector): Provider` builds an
  on-demand laravel/ai provider named `db-{id}` from a connector with
  `Ai::build()`. Nothing is written to `config('ai.providers')`. The provider
  serializes with its configuration encrypted (via `APP_KEY`), so it can be
  handed to queued agents, and can be returned from an agent's `provider()`
  method or referenced by name (`prompt(..., provider: $provider->name())`)
  for the rest of the operation.
- `ModelRegistry` and `ConnectorManager` now depend on
  `Laravel\Ai\AiManager` (injected by the service provider).

#### Model roles

- `LmSomeco\AiModels\Enums\ModelRole` — what the SDK uses a model for on a
  given provider: `Text`, `CheapestText`, `SmartestText`, `Image`,
  `Embeddings`, `Audio`, `Transcription`, `Reranking`, `Classification`. Each
  case maps to the SDK capability contract and method it is read from
  (`contract()`, `method()`), e.g. `ModelRole::CheapestText` →
  `TextProvider::cheapestTextModel()`.
- `ModelRole::modelFor(Provider $provider): ?string` and
  `ModelRole::assignments(Provider $provider): array<string, list<ModelRole>>`.
  Capabilities a provider does not implement are skipped, and a provider
  method that throws (e.g. an `openai-compatible` provider without
  `models.text.default`) is treated as "no model for this role".
- `AiModel::$roles` (`list<ModelRole>`), `AiModel::is(ModelRole $role): bool`
  and `AiModel::withRoles(array $roles): AiModel`. Every model returned by the
  registry is tagged with the roles its provider assigns it.
- `ModelRegistry::default(Provider|Lab|string $provider, ModelRole $role = ModelRole::Text): ?AiModel`
  (also on the facade) — the listed model holding a role, e.g.
  `AiModels::default('anthropic', ModelRole::CheapestText)`.
- Roles honour each provider's `models.*` overrides in `config/ai.php` and are
  applied **after** the cache read, so changing a default needs no cache
  refresh.
- `roles` in `AiModel::toArray()` (and therefore `ai:models --json`), rendered
  as their string values (`text`, `text.cheapest`, ...).
- A **Roles** column in the `ai:models` table.

#### Errors

- Listing failures are rethrown as laravel/ai's failover exceptions, using the
  SDK's own `Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors` mapping:
  - connection failures and timeouts → `ProviderConnectionException`
  - HTTP 429 → `RateLimitedException`
  - HTTP 402 → `InsufficientCreditsException`
  - transient 5xx (502, 503, 504, 520, 522, 524 by default) →
    `ProviderOverloadedException`

  All four extend `Laravel\Ai\Exceptions\AiException`, implement
  `FailoverableException`, name the provider in their message, and keep the
  original HTTP exception as `getPrevious()`. Other statuses (e.g. 401) still
  throw `Illuminate\Http\Client\RequestException`.
- `overloaded_status_codes` resolver option for labs with their own transient
  statuses; the shipped config sets Anthropic's `529` and Groq's `498`.
- `ai:models` reports an unreachable, rate-limited or out-of-credit provider as
  a one-line error and exits with a failure status, instead of an uncaught
  exception and stack trace.

#### Providers and resolvers

- Resolver for the SDK's `openai-compatible` driver (`Lab::OpenAICompatible`) —
  vLLM, LM Studio, LiteLLM, Ollama's OpenAI endpoint and other self-hosted or
  third-party OpenAI-style servers. The provider's `url` is required; the API
  key is optional (the bearer token is only sent when a key is set).
- `LmSomeco\AiModels\Resolvers\Resolver` — abstract base class for HTTP
  resolvers. Subclasses implement `fetch()`; the base class supplies
  `configured()`, the `final` `models()` wrapper with SDK exception mapping,
  and the helpers `name()`, `driver()`, `apiKey()`, `baseUrl()`, `timeout()`,
  `request()`, `newModel()` and `overloadedStatusCodes()`.
- `requires_key` resolver option (default `true`) — set it to `false` for
  drivers whose model listing works without an API key.
- Default `url` fallbacks for OpenAI (`https://api.openai.com/v1`) and
  Anthropic (`https://api.anthropic.com/v1`) in `config/ai-models.php`.

#### Data

- `AiModel::$driver` (`Lab`) — the laravel/ai driver behind the provider the
  model was listed under; `driver` in `AiModel::toArray()`; a **Driver** column
  in the `ai:models` table.

#### Documentation

- [UPGRADE.md](UPGRADE.md) — full 1.x → 2.0 upgrade guide.
- [Release notes for 2.0.0](docs/releases/v2.0.0.md).
- New sections in `docs/usage.md` (model roles, errors), `docs/caching.md`
  (versioned keys), `docs/database-connectors.md` (on-demand providers, agent
  and queue usage) and `docs/extending.md` (the `Resolver` base class and
  resolver options).

### Changed

#### Breaking

- **Requires `laravel/ai` ^1.0.1.** 0.x is no longer supported. (1.0.1 is the
  first release with `Ai::build()`.) PHP 8.3+ and Laravel 12/13 requirements
  are unchanged.
- **`AiModel::$provider` is now the provider name** (`string`) — the
  `config/ai.php` key the model was listed under (e.g. `openai`), or the
  on-demand provider's name (e.g. `db-5`, `tenant-42`). The `Lab` moved to the
  new `AiModel::$driver`. In `toArray()` / `--json`, `provider` is now the name
  and `driver` holds the Lab value.
- **`AiModel`'s constructor signature changed**: `provider` is a `string`, a
  required `driver` (`Lab`) follows it, and `roles` was appended. Positional
  construction breaks; named arguments only need `driver:` added.
- **`ProviderResolver::provider()` returns the laravel/ai `Provider`
  instance** instead of a `Lab`.
- **Resolvers are constructed with `(HttpFactory $http, Provider $provider, array $config)`**
  instead of `(HttpFactory $http, Lab $lab, array $config)`, and `$config` no
  longer contains the provider's credentials or URL — only the resolver
  defaults from `config/ai-models.php` plus `timeout`. Read credentials via
  `apiKey()` / `baseUrl()` (or the `Provider`), not `$this->config['key']`.
- **Shipped resolvers extend `Resolver`**: their `models()` is `final` (move
  overrides to `fetch()`), the `$lab` property is gone, and
  `OpenAiCompatibleResolver::apiKey()` / `baseUrl()` moved to the base class.
- **`Resolver::configured()` requires a base URL** (from the provider or the
  resolver's `url` fallback) in addition to an API key (unless
  `requires_key` is `false`).
- **`Contracts\Connector::getProvider(): string` is now `getDriver(): Lab`.**
  `IsConnector` implements it from the unchanged `provider` column.
- **Resolver entries in `config/ai-models.php` name their class under
  `resolver`** (was `driver`), so "driver" only ever means the `Lab`. A
  published 1.x config therefore matches no resolvers until it is updated.
- **`ConnectorManager`'s constructor gained an `AiManager $ai` parameter**
  between `$registry` and `$connectorModel`.
- **`ModelRegistry`'s constructor gained an `AiManager $ai` parameter.**
- **Cache keys are versioned**: `{prefix}:v2:{provider-name}` (was
  `{prefix}:{provider-name}`). 1.x entries are never read — their serialized
  `AiModel`s hold a `Lab` in the now `string`-typed `$provider` and cannot be
  unserialized by 2.0.
- **Connector model lists are cached under `db-{id}`** (was
  `connector:{id}`); clear one with `AiModels::refresh('db-'.$id)`.
- **`ai:models` table columns** are now Provider (name), Driver, Model ID,
  Name, Context, Roles, Created; the `provider` argument is described as a
  provider name rather than a Lab value.

#### Non-breaking

- Providers are instantiated through laravel/ai's `AiManager`. As a result:
  - a `config/ai.php` entry whose `driver` is a `Lab` case (allowed by the 1.x
    SDK config) is recognised;
  - providers whose driver has no resolver (e.g. Bedrock, Gemini) are never
    instantiated;
  - provider instances are cached per name by the SDK for the life of the
    process (see UPGRADE.md if you mutate `config('ai.providers')` at runtime).
- The SDK's default `config/ai.php` ships an `openai-compatible` entry; it
  now appears in `ai:models` / `AiModels::all()` once `OPENAI_COMPATIBLE_URL`
  is set.
- `AiModels::refresh()` accepts a `Provider` instance.
- `AnthropicResolver` reads its default URL and `anthropic-version` from
  resolver config, and uses the shared `request()` (same headers, base URL and
  timeout as before).
- The model-cache `usable()` check keys off `AiModel::$driver`.
- CI tests against `laravel/ai` 1.0.1 and the latest 1.x on PHP 8.3/8.4 and
  Laravel 12/13.

### Removed

- **`ConnectorManager::configure()`** — use `ConnectorManager::provider()`.
  Connectors are no longer injected into `config('ai.providers')`.
- **`ModelRegistry::driver()`** (and `AiModels::driver()`) — use
  `AiModels::provider(Ai::build([...]))`. The opt-out of caching via a `null`
  `$cacheKey` is gone; pass `fresh: true` to bypass the cache for one call.
- **`ModelRegistry::connector()`** (and `AiModels::connector()`) — use
  `ConnectorManager::models()`.
- The `connector:{id}` cache-key convention.
- Support for `laravel/ai` 0.7–0.11.

### Fixed

- A `config/ai.php` provider entry with a `Lab` case as its `driver` was
  silently ignored.
- A provider entry with no `driver` key is skipped instead of reaching the SDK
  (which reads `driver` unguarded).
- Distinct providers on the same driver (e.g. `openai` and a `db-5`
  connector pointing at OpenAI) produced indistinguishable models — both
  reported `provider: Lab::OpenAI`.

### Dependencies

- `laravel/ai`: `^0.7|^0.8|^0.9|^0.10|^0.11` → `^1.0.1`.
- Through laravel/ai 1.x: `aws/aws-sdk-php` is no longer installed by default
  (now optional in the SDK, only needed for Bedrock), and `laravel/mcp` below
  1.0 conflicts with it.

## [1.0.6] - 2026-08-29

### Fixed

- Corrected the `laravel/ai` constraint to `^0.7|^0.8|^0.9|^0.10|^0.11`.
  1.0.5 shipped `^.11`, which Composer cannot parse, so 1.0.6 is the first
  release installable alongside `laravel/ai` 0.11.

## [1.0.5] - 2026-08-29

### Changed

- Attempted to widen the `laravel/ai` constraint to include 0.11.

### Known issues

- The constraint was published as `^0.7|^0.8|^0.9|^0.10|^.11`; Composer
  rejects it (`Could not parse version constraint ^.11`). Use 1.0.6 instead.

## [1.0.4] - 2026-08-19

### Added

- Anthropic modality, context-window, and output-token mapping from its current
  model-list response, with documented text/image defaults for older payloads.
- `OpenAiResolver`, which enriches official OpenAI model families with their
  documented modalities because OpenAI's model-list response omits them.

## [1.0.3] - 2026-08-19

### Added

- `AiModel::$outputModalities`, plus `accepts()` and `generates()` helpers, so
  applications can filter returned collections by input/output capability.
- OpenRouter output modality mapping from `architecture.output_modalities`.

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

[Unreleased]: https://github.com/lmandcreative/laravel-ai-models/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/lmandcreative/laravel-ai-models/compare/v1.0.6...v2.0.0
[1.0.6]: https://github.com/lmandcreative/laravel-ai-models/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/lmandcreative/laravel-ai-models/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/lmandcreative/laravel-ai-models/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/lmandcreative/laravel-ai-models/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/lmandcreative/laravel-ai-models/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/lmandcreative/laravel-ai-models/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/lmandcreative/laravel-ai-models/releases/tag/v1.0.0
