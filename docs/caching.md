# Caching

Model lists are fetched over HTTP, so they're cached per provider rather than
re-requested on every call.

## Cache key format

Every key is `{prefix}:v2:{provider-name}`, where `prefix` is
`ai-models.cache.prefix` (default `"ai-models"`) and the provider name is
whatever the laravel/ai provider is called:

- Providers from `config/ai.php`: their config key, e.g. `ai-models:v2:openai`.
- Database connectors (via `ConnectorManager::models()`): the connector's
  on-demand provider name, e.g. `ai-models:v2:db-9c1e...`.
- Other `Ai::build()` providers: the `name` you gave it, or the SDK's generated
  `ondemand_{hash}` name (stable for identical configuration).

The `v2` segment changes whenever the cached `AiModel` shape changes
incompatibly, so entries written by an older major release are never read
back (1.x entries can't even be unserialized by 2.x). Old entries simply
expire, or can be cleared from the store.

Model [roles](usage.md#model-roles) are **not** cached: they are applied after
the cache read, so changing a provider's `models.*` defaults takes effect
immediately.

## TTL

Controlled by `AI_MODELS_CACHE_TTL` (`ai-models.cache.ttl`), in seconds:

- A number of seconds → cached with `Cache::put($key, $models, $ttl)`.
- `null` → cached **forever**, until explicitly busted. Useful for providers
  whose catalog rarely changes.

Default is `3600` (one hour).

## Store

`AI_MODELS_CACHE_STORE` (`ai-models.cache.store`) selects which cache store
to use. Leave it `null` to use your application's default store
(`cache.default`).

## Busting the cache

- CLI: `php artisan ai:models --refresh` (or `ai:models openai --refresh` for
  one provider).
- Code: `AiModels::refresh()` clears every provider currently declared in
  `config('ai.providers')`; `AiModels::refresh('groq')` (or a `Lab`, or a
  `Provider` instance) clears just one. For a database connector pass its
  provider name: `AiModels::refresh('db-'.$connector->getConnectorId())`.
- Passing `$fresh = true` to `all()`, `provider()`, or
  `ConnectorManager::models()` forgets that one cache entry immediately
  before re-fetching and re-caching — equivalent to `--refresh` for that call.

## Unusable entries

A cache entry that isn't a collection of intact `AiModel` objects — e.g. one
written by other code under a colliding key, or containing classes this
deployment can't load — is treated as a miss: it's dropped and the list is
refetched.
