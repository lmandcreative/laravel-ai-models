# Caching

Model lists are fetched over HTTP, so they're cached per provider (or per
connector) rather than re-requested on every call.

## Cache key format

Every key is prefixed with `ai-models.cache.prefix` (default `"ai-models"`):

- Named providers (via `provider()` / `all()`): `{prefix}:{provider-name}`,
  e.g. `ai-models:openai`.
- Database connectors (via `ConnectorManager::models()` /
  `ModelRegistry::connector()`): `{prefix}:connector:{uuid}`, e.g.
  `ai-models:connector:9c1e...`.
- Ad-hoc `driver()` calls: whatever `$cacheKey` you pass, prefixed the same
  way — e.g. `driver(..., cacheKey: 'foo')` caches under `{prefix}:foo`.

## TTL

Controlled by `AI_MODELS_CACHE_TTL` (`ai-models.cache.ttl`), in seconds:

- A number of seconds → cached with `Cache::remember($key, $ttl, ...)`.
- `null` → cached **forever** (`Cache::rememberForever`), until explicitly
  busted. Useful for providers whose catalog rarely changes.

Default is `3600` (one hour).

## Store

`AI_MODELS_CACHE_STORE` (`ai-models.cache.store`) selects which cache store
to use. Leave it `null` to use your application's default store
(`cache.default`).

## Busting the cache

- CLI: `php artisan ai:models --refresh` (or `ai:models openai --refresh` for
  one provider).
- Code: `AiModels::refresh()` clears every provider currently declared in
  `config('ai.providers')`; `AiModels::refresh(Lab::Groq)` (or a provider
  name string) clears just one. Passing a raw string that matches a
  connector's cache key (e.g. `AiModels::refresh('connector:'.$id)`) also
  works, since `refresh()` builds the same `{prefix}:{name}` key internally.
- Passing `$fresh = true` to `all()`, `provider()`, `driver()`, or
  `ConnectorManager::models()` forgets that one cache entry immediately
  before re-fetching and re-caching — equivalent to `--refresh` for that call.

## Skipping the cache entirely

`driver()` (and anything built on it) only caches when you pass a
`$cacheKey`. Calling `AiModels::driver($lab, $config)` with no `cacheKey`
fetches fresh every time and never touches the cache store — useful for
one-off lookups you don't want to pollute the cache with.
