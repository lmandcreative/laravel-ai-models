# Laravel AI Models

A companion package for the [Laravel AI SDK](https://laravel.com/ai) (`laravel/ai`)
that lists and caches the currently-available models for each provider you have
configured. It reads your credentials straight from `config/ai.php` and keys
everything on the SDK's own `Laravel\Ai\Enums\Lab` enum, so there's nothing to
configure twice.

## Requirements

- PHP 8.3+
- `laravel/ai` ^0.7, ^0.8, ^0.9 or ^0.10

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
php artisan ai:models openai          # one provider (a Lab value)
php artisan ai:models --refresh       # bypass the cache
php artisan ai:models openrouter --json
```

### Facade / service

```php
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Facades\AiModels;

AiModels::all();                       // Collection<AiModel>
AiModels::provider(Lab::Anthropic);    // accepts a Lab or its string value
AiModels::configuredProviders();       // Collection<int, string> of provider names
AiModels::refresh();                   // clear all cached lists
AiModels::refresh(Lab::Groq);          // clear one

$model = AiModels::provider(Lab::OpenAI)->firstWhere('id', 'gpt-4o');
$model->provider;       // Laravel\Ai\Enums\Lab::OpenAI
$model->id;             // 'gpt-4o'
$model->contextWindow;  // int|null
$model->raw;            // untouched provider payload
```

The `ModelRegistry` is resolvable from the container if you prefer DI over the
facade.

## Provider coverage

| Provider (`Lab`)        | Resolver                    | Status |
|--------------------------|-----------------------------|--------|
| `OpenAI`                 | `OpenAiCompatibleResolver`  | ✅ live |
| `Groq`                   | `OpenAiCompatibleResolver`  | ✅ live |
| `Mistral`                | `OpenAiCompatibleResolver`  | ✅ live |
| `DeepSeek`               | `OpenAiCompatibleResolver`  | ✅ live |
| `xAI`                    | `OpenAiCompatibleResolver`  | ✅ live |
| `OpenRouter`             | `OpenRouterResolver`        | ✅ live (rich metadata, no key needed) |
| `Anthropic`              | `AnthropicResolver`         | ✅ live |
| Gemini, Ollama, Azure, Cohere, ElevenLabs, Bedrock, Jina, VoyageAI | — | ⏳ not yet implemented |

See [`docs/extending.md`](docs/extending.md) for per-provider notes and how to
add a resolver — contributions welcome.

## Documentation

- [Installation](docs/installation.md) — publish tags, env vars, how credentials are read
- [Usage](docs/usage.md) — the `ai:models` command and the full facade/DI API
- [Database connectors](docs/database-connectors.md) — optional DB-backed provider configs
- [Caching](docs/caching.md) — cache keys, TTL and busting
- [Extending](docs/extending.md) — writing your own `ProviderResolver`

## License

MIT.
