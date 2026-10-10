# Laravel AI Models

A companion package for the [Laravel AI SDK](https://laravel.com/ai) (`laravel/ai`)
that lists and caches the currently-available models for each provider you have
configured. It resolves providers through the SDK itself — the entries in
`config/ai.php`, or any provider built on demand with `Ai::build()` — so there's
nothing to configure twice.

## Requirements

- PHP 8.3+
- `laravel/ai` ^1.0.1

Upgrading from 1.x? See [UPGRADE.md](UPGRADE.md).

## Install

```bash
composer require lmsomeco/laravel-ai-models
php artisan vendor:publish --tag=ai-models-config       # optional
php artisan vendor:publish --tag=ai-models-migrations   # only if using database connectors
```

Any provider with credentials already set in `config/ai.php` is picked up
automatically — no extra keys or env vars.

## Usage

### Artisan

```bash
php artisan ai:models                 # every configured provider
php artisan ai:models openai          # one provider (its config/ai.php name)
php artisan ai:models --refresh       # bypass the cache
php artisan ai:models openrouter --json
```

### Facade / service

```php
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Enums\ModelRole;
use LmSomeco\AiModels\Facades\AiModels;

AiModels::all();                       // Collection<AiModel>
AiModels::provider('anthropic');       // a config/ai.php provider name...
AiModels::provider(Lab::Anthropic);    // ...or a Lab...
AiModels::provider(Ai::build([         // ...or any laravel/ai Provider instance
    'driver' => 'openai-compatible',
    'url' => 'https://llm.acme.internal/v1',
]));
AiModels::default('openai');                            // the SDK's default text model
AiModels::default('openai', ModelRole::CheapestText);   // or any other role
AiModels::configuredProviders();       // Collection<int, string> of provider names
AiModels::refresh();                   // clear all cached lists
AiModels::refresh('groq');             // clear one

$model = AiModels::provider('openai')->firstWhere('id', 'gpt-5.5');
$model->provider;         // 'openai' — the provider name it was listed under
$model->driver;           // Laravel\Ai\Enums\Lab::OpenAI
$model->id;               // 'gpt-5.5'
$model->contextWindow;    // int|null
$model->modalities;       // input modalities; [] when unknown
$model->outputModalities; // output modalities; [] when unknown
$model->roles;            // [ModelRole::Text, ...] — what the SDK uses it for
$model->is(ModelRole::SmartestText);
$model->raw;              // untouched provider payload
```

Registry methods return Laravel collections, so model properties and helpers
can be used directly when filtering:

```php
$models = AiModels::all()->filter(
    fn ($model) => $model->outputModalities === ['text'],
);
```

This strict form also excludes models whose output capabilities are unknown.

Listing failures surface as the SDK's own failover exceptions
(`RateLimitedException`, `ProviderOverloadedException`,
`ProviderConnectionException`, `InsufficientCreditsException`), so you can
handle them exactly like a failed prompt.

The `ModelRegistry` is resolvable from the container if you prefer DI over the
facade.

## Provider coverage

| Driver (`Lab`)           | Resolver                    | Status |
|--------------------------|-----------------------------|--------|
| `OpenAI`                 | `OpenAiResolver`            | ✅ live (catalog-enriched modalities) |
| `Groq`                   | `OpenAiCompatibleResolver`  | ✅ live |
| `Mistral`                | `OpenAiCompatibleResolver`  | ✅ live |
| `DeepSeek`               | `OpenAiCompatibleResolver`  | ✅ live |
| `xAI`                    | `OpenAiCompatibleResolver`  | ✅ live |
| `OpenAICompatible`       | `OpenAiCompatibleResolver`  | ✅ live (URL required, key optional) |
| `OpenRouter`             | `OpenRouterResolver`        | ✅ live (rich metadata, no key needed) |
| `Anthropic`              | `AnthropicResolver`         | ✅ live |
| Gemini, Ollama, Azure, Cohere, ElevenLabs, Bedrock, Jina, VoyageAI, TypeSafe | — | ⏳ not yet implemented |

See [`docs/extending.md`](docs/extending.md) for per-provider notes and how to
add a resolver — contributions welcome.

## Documentation

- [Installation](docs/installation.md) — publish tags, env vars, how credentials are read
- [Usage](docs/usage.md) — the `ai:models` command, the facade/DI API and model roles
- [Database connectors](docs/database-connectors.md) — optional DB-backed providers via `Ai::build()`
- [Caching](docs/caching.md) — cache keys, TTL and busting
- [Extending](docs/extending.md) — writing your own resolver
- [Upgrading from 1.x](UPGRADE.md)

## License

MIT.
