# Changelog

All notable changes to `lmsomeco/laravel-ai-models` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
