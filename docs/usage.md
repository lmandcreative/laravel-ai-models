# Usage

## Artisan command

```
php artisan ai:models {provider?} {--refresh} {--json}
```

| Argument/Option | Description |
|------------------|--------------|
| `provider`        | Optional. A provider name from `config('ai.providers')` (e.g. `openai`). Omit to list every configured provider. |
| `--refresh`        | Bypass the cache and re-fetch from the provider's API. |
| `--json`            | Print raw JSON (`AiModel::toArray()` for each model) instead of a table. |

Examples:

```bash
php artisan ai:models                 # every configured provider
php artisan ai:models anthropic       # one provider
php artisan ai:models --refresh       # bypass the cache
php artisan ai:models openrouter --json
```

The table shows each model's provider name, driver, ID, display name, context
window, [roles](#model-roles) and creation date.

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
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Enums\ModelRole;
use LmSomeco\AiModels\Facades\AiModels;
```

### `all(bool $fresh = false): Collection<int, AiModel>`

Every model across every configured provider (flattened).

### `provider(Provider|Lab|string $provider, bool $fresh = false): Collection<int, AiModel>`

Models for a single provider. Pass either:

- a `config('ai.providers')` name (a `Lab` is converted to its string value),
  or
- any `Laravel\Ai\Contracts\Providers\Provider` instance — e.g.
  `Ai::instance('openai')`, or a provider built on demand with `Ai::build()`:

  ```php
  AiModels::provider(Ai::build([
      'name' => 'tenant-42',
      'driver' => 'anthropic',
      'key' => $tenant->anthropic_key,
  ]));
  ```

Behaviour:

- Throws `InvalidArgumentException` if a name isn't in `config('ai.providers')`,
  or the provider's driver has no resolver registered.
- Returns an **empty collection** (not an error) if the provider lacks the
  credentials its resolver needs — e.g. a missing API key.
- Throws the SDK's failover exceptions when the provider's API fails — see
  [Errors](#errors).

### `default(Provider|Lab|string $provider, ModelRole $role = ModelRole::Text): ?AiModel`

The listed model holding a [role](#model-roles) on that provider — by default
the SDK's default text model. Returns `null` if the provider doesn't list that
model (or doesn't support the capability).

```php
AiModels::default('openai');                              // default text model
AiModels::default('anthropic', ModelRole::CheapestText);
AiModels::default('openai', ModelRole::Embeddings);
```

### `configuredProviders(): Collection<int, string>`

The names of providers (from `config('ai.providers')`) that have a resolver
*and* the credentials that resolver needs.

### `providers(): Collection<string, ProviderResolver>`

Every provider entry from `config('ai.providers')` whose driver has a
resolver, keyed by provider name and built into resolver instances —
regardless of whether each one is `configured()`. Providers without a resolver
are never instantiated.

### `refresh(Provider|Lab|string|null $provider = null): void`

Drop cached models for one provider, or — when called with no argument — every
provider currently declared in `config('ai.providers')`.

## Model roles

The SDK knows which model each provider uses by default for every capability
(and which text models are its cheapest and smartest). Every `AiModel`
returned by the registry carries those as `LmSomeco\AiModels\Enums\ModelRole`
cases:

| Role                          | Value             | From the SDK provider's |
|-------------------------------|-------------------|-------------------------|
| `ModelRole::Text`             | `text`            | `defaultTextModel()` |
| `ModelRole::CheapestText`     | `text.cheapest`   | `cheapestTextModel()` |
| `ModelRole::SmartestText`     | `text.smartest`   | `smartestTextModel()` |
| `ModelRole::Image`            | `image`           | `defaultImageModel()` |
| `ModelRole::Embeddings`       | `embeddings`      | `defaultEmbeddingsModel()` |
| `ModelRole::Audio`            | `audio`           | `defaultAudioModel()` |
| `ModelRole::Transcription`    | `transcription`   | `defaultTranscriptionModel()` |
| `ModelRole::Reranking`        | `reranking`       | `defaultRerankingModel()` |
| `ModelRole::Classification`   | `classification`  | `defaultClassificationModel()` |

```php
$model->roles;                          // [ModelRole::Text, ModelRole::SmartestText]
$model->is(ModelRole::CheapestText);    // bool

AiModels::provider('openai')->filter->is(ModelRole::Text);
```

Roles honour each provider's `models.*` overrides in `config/ai.php`, and are
applied **after** the cache read — change a default and it shows up on the
next call, without refreshing the model cache. A role whose model the
provider doesn't list (or a capability the provider doesn't have) is simply
absent.

## Errors

Resolvers rethrow HTTP failures as the SDK's own failover exceptions, so you
can handle a failed listing exactly like a failed prompt:

| Situation                                  | Exception |
|--------------------------------------------|-----------|
| Connection failure / timeout               | `Laravel\Ai\Exceptions\ProviderConnectionException` |
| HTTP 429                                   | `Laravel\Ai\Exceptions\RateLimitedException` |
| HTTP 402                                   | `Laravel\Ai\Exceptions\InsufficientCreditsException` |
| Transient 5xx (plus Anthropic's 529, Groq's 498) | `Laravel\Ai\Exceptions\ProviderOverloadedException` |
| Anything else (e.g. 401)                   | `Illuminate\Http\Client\RequestException` |

All of the above except `RequestException` extend
`Laravel\Ai\Exceptions\AiException` and implement `FailoverableException`.
The per-lab "overloaded" status codes are set by `overloaded_status_codes` on
each resolver entry in `config/ai-models.php`.

## The `AiModel` DTO

`LmSomeco\AiModels\Data\AiModel` is a `readonly` value object, normalized the
same way regardless of provider:

| Property           | Type                     | Notes |
|----------------------|--------------------------|-------|
| `provider`            | `string`                  | The provider name the model was listed under: its `config/ai.php` key (e.g. `openai`) or on-demand name (e.g. `db-5`). |
| `driver`              | `Lab`                     | The laravel/ai driver behind that provider. |
| `id`                   | `string`                  | The provider's model ID. |
| `name`                 | `?string`                 | Display name, when the provider supplies one. |
| `contextWindow`        | `?int`                    | Only populated where the provider exposes it (e.g. OpenRouter, Anthropic). |
| `maxOutputTokens`      | `?int`                    | Same. |
| `modalities`           | `list<string>`            | Input modalities, e.g. `["text", "image"]`. Empty array if unknown. |
| `outputModalities`     | `list<string>`            | Output modalities the model can generate. Empty array if unknown. |
| `roles`                | `list<ModelRole>`         | See [Model roles](#model-roles). |
| `createdAt`            | `?DateTimeImmutable`      | When the provider reports it. |
| `raw`                  | `array<string, mixed>`    | The untouched payload returned by the provider. |

`AiModel::accepts(string $modality)` and `AiModel::generates(string $modality)`
make modality checks readable. Registry methods return standard Laravel
collections, so models can be filtered by these helpers or any other property:

```php
use LmSomeco\AiModels\Data\AiModel;

$textOnly = AiModels::all()->filter(
    fn (AiModel $model): bool => $model->outputModalities === ['text'],
);

$withoutKnownImageOrAudioGeneration = AiModels::all()->reject(
    fn (AiModel $model): bool => $model->generates('image') || $model->generates('audio'),
);
```

An empty modality list means the capability is unknown. The strict first
example excludes unknown models; the second keeps them and only excludes models
known to generate image or audio output.

- OpenRouter modalities come directly from `architecture.input_modalities` and
  `architecture.output_modalities` in its model-list response.
- Anthropic input modalities, context size, and output-token limit come from
  its current model-list metadata. Text output and the fallback for older
  responses follow Anthropic's documented capabilities for current Claude
  models.
- OpenAI's model-list API does not return capabilities. The OpenAI resolver
  derives modalities from documented official model families, including
  snapshots and fine-tuned model IDs. Unrecognized custom IDs remain unknown.

`AiModel::toArray()` returns a snake_case array of every field **except**
`raw` (dropped to keep `--json` output and API responses compact); `driver`
and `roles` are rendered as their string values — see `src/Data/AiModel.php`
for the exact shape.
