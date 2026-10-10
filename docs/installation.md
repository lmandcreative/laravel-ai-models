# Installation

## Requirements

- PHP 8.3+
- `laravel/ai` ^1.0.1 (already configured with your provider credentials in
  `config/ai.php`)

## Install

```bash
composer require lmsomeco/laravel-ai-models
```

The package auto-registers `AiModelsServiceProvider` and the `AiModels` facade
alias via Laravel's package discovery — no manual registration needed.

## Publishing

Two publish tags are available, both optional:

```bash
# This package's own config (cache store/TTL, timeout, resolver map)
php artisan vendor:publish --tag=ai-models-config

# The ai_connectors migration — only needed for database-backed connectors
php artisan vendor:publish --tag=ai-models-migrations
```

Nothing needs to be published to get started: sensible defaults ship in
`config/ai-models.php`, and it's merged automatically even if you never
publish it.

## Credentials

This package does **not** duplicate provider credentials. Providers are
resolved through the Laravel AI SDK itself, so credentials come from
`config/ai.php` — keyed by provider name, with a `driver` that is a `Lab` enum
case or its string value:

```php
// config/ai.php
'providers' => [
    'openai' => [
        'driver' => 'openai',          // or Lab::OpenAI
        'key' => env('OPENAI_API_KEY'),
    ],
],
```

Any provider entry whose driver has a registered resolver (see
`config/ai-models.php`) is picked up automatically. A provider without the
credentials its resolver needs is simply skipped (see [Usage](usage.md)) —
nothing throws for being unconfigured.

Providers that never touch config work too: pass any provider built with
`Ai::build([...])` straight to `AiModels::provider()`.

## Environment variables

All are optional; every one has a working default.

| Variable                | Default   | Purpose |
|--------------------------|-----------|---------|
| `AI_MODELS_CACHE_STORE`  | `null`    | Cache store to use (`null` = your app's default store). |
| `AI_MODELS_CACHE_TTL`    | `3600`    | Seconds to cache each provider's model list; `null` caches forever until manually refreshed. |
| `AI_MODELS_TIMEOUT`      | `15`      | HTTP timeout (seconds) for resolver requests, per provider unless overridden. |
| `AI_MODELS_CONNECTORS`   | `false`   | Enables the optional database-backed connector system — see [Database connectors](database-connectors.md). |

See also: [Usage](usage.md), [Caching](caching.md), [Extending](extending.md).
