# Usage

## Artisan command

```
php artisan ai:models {provider?} {--refresh} {--json}
```

| Argument/Option | Description |
|------------------|--------------|
| `provider`        | Optional. A provider name from `config('ai.providers')` (typically a `Lab` value, e.g. `openai`). Omit to list every configured provider. |
| `--refresh`        | Bypass the cache and re-fetch from the provider's API. |
| `--json`            | Print raw JSON (`AiModel::toArray()` for each model) instead of a table. |

Examples:

```bash
php artisan ai:models                 # every configured provider
php artisan ai:models anthropic       # one provider
php artisan ai:models --refresh       # bypass the cache
php artisan ai:models openrouter --json
```

### Failure behavior

- An unknown `provider` argument (not present in `config('ai.providers')`, or
  present but with no matching resolver) prints an error and the list of
  currently configured providers, then exits with a failure status.
- If the resulting list of models is empty (e.g. the provider is declared but
  has no API key set), the command prints a warning and exits **successfully**
  — an unconfigured provider is not treated as an error.

## Facade / dependency injection

`LmSomeco\AiModels\Facades\AiModels` proxies to the `ModelRegistry` singleton,
which is also resolvable via constructor injection or the container.

```php
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Facades\AiModels;
```

### `all(bool $fresh = false): Collection<int, AiModel>`

Every model across every configured provider (flattened).

### `provider(Lab|string $provider, bool $fresh = false): Collection<int, AiModel>`

Models for a single provider, looked up by its `config('ai.providers')` name
(a `Lab` instance is converted to its string value).

- Throws `InvalidArgumentException` if the name isn't in `config('ai.providers')`,
  or is present but has no resolver registered for its `driver`.
- Returns an **empty collection** (not an error) if the provider is configured
  but not `configured()` — e.g. missing an API key.

### `configuredProviders(): Collection<int, string>`

The names of providers (from `config('ai.providers')`) that have a resolver
*and* the credentials that resolver needs. This is a list of provider
**names** (strings), not `Lab` instances.

### `providers(): Collection<string, ProviderResolver>`

Every provider entry from `config('ai.providers')` that has a matching
resolver, keyed by provider name and built into resolver instances —
regardless of whether each one is `configured()`.

### `driver(Lab|string $driver, array $config = [], ?string $cacheKey = null, bool $fresh = false): Collection<int, AiModel>`

Resolve models for an ad-hoc provider that isn't declared in
`config('ai.providers')` at all — pass the underlying `Lab` driver and
credentials directly. See [Database connectors](database-connectors.md) and
[Caching](caching.md) for how `$cacheKey` behaves.

### `refresh(Lab|string|null $provider = null): void`

Drop cached models for one provider (or cache key), or — when called with no
argument — every provider currently declared in `config('ai.providers')`.

## The `AiModel` DTO

`LmSomeco\AiModels\Data\AiModel` is a `readonly` value object, normalized the
same way regardless of provider:

| Property           | Type                     | Notes |
|----------------------|--------------------------|-------|
| `provider`            | `Lab`                     | The lab that actually served this model. |
| `id`                   | `string`                  | The provider's model ID. |
| `name`                 | `?string`                 | Display name, when the provider supplies one. |
| `contextWindow`        | `?int`                    | Only populated where the provider exposes it (e.g. OpenRouter). |
| `maxOutputTokens`      | `?int`                    | Same. |
| `modalities`           | `list<string>`            | Input modalities, e.g. `["text", "image"]`. Empty array if unknown. |
| `createdAt`            | `?DateTimeImmutable`      | When the provider reports it. |
| `raw`                  | `array<string, mixed>`    | The untouched payload returned by the provider. |

`AiModel::toArray()` returns a snake_case array of every field **except**
`raw` (dropped to keep `--json` output and API responses compact) — see
`src/Data/AiModel.php` for the exact shape.
