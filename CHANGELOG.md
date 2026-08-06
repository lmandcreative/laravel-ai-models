# Changelog

All notable changes to `lmsomeco/laravel-ai-models` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
