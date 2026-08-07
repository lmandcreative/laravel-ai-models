<?php

declare(strict_types=1);

namespace LmSomeco\AiModels;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Contracts\Connector;
use LmSomeco\AiModels\Contracts\ProviderResolver;
use LmSomeco\AiModels\Data\AiModel;

class ModelRegistry
{
    /**
     * The driver-to-resolver map (keyed by Lab value), from config/ai-models.php.
     *
     * @var Collection<string, array<string, mixed>>|null
     */
    protected ?Collection $resolverMap = null;

    public function __construct(
        protected Container $container,
        protected CacheFactory $cache,
        protected Config $config,
    ) {}

    /**
     * Every provider entry in config/ai.php that has a resolver for its driver,
     * built into resolver instances and keyed by the provider name.
     *
     * Because resolvers are selected by the entry's "driver" (a Lab value),
     * dynamically-injected providers (e.g. "db-{id}" connectors registered at
     * runtime via config(['ai.providers...' => ...])) are supported transparently.
     *
     * @return Collection<string, ProviderResolver>
     */
    public function providers(): Collection
    {
        return collect((array) $this->config->get('ai.providers', []))
            ->map(fn (array $entry): ?ProviderResolver => $this->makeResolver($entry))
            ->filter();
    }

    /**
     * The names of providers that have the credentials they need to run.
     *
     * @return Collection<int, string>
     */
    public function configuredProviders(): Collection
    {
        return $this->providers()
            ->filter(fn (ProviderResolver $resolver): bool => $resolver->configured())
            ->keys()
            ->values();
    }

    /**
     * Every model across every configured provider.
     *
     * @return Collection<int, AiModel>
     */
    public function all(bool $fresh = false): Collection
    {
        return $this->configuredProviders()
            ->flatMap(fn (string $name): Collection => $this->provider($name, $fresh))
            ->values();
    }

    /**
     * The models for a single provider, by its config/ai.php name (or Lab).
     *
     * @return Collection<int, AiModel>
     */
    public function provider(Lab|string $provider, bool $fresh = false): Collection
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;
        $entries = (array) $this->config->get('ai.providers', []);

        if (! array_key_exists($name, $entries)) {
            throw new InvalidArgumentException("No provider named [{$name}] configured in config('ai.providers').");
        }

        $resolver = $this->makeResolver($entries[$name]);

        if (! $resolver instanceof ProviderResolver) {
            $driver = $entries[$name]['driver'] ?? 'none';

            throw new InvalidArgumentException("No model resolver registered for provider [{$name}] (driver: {$driver}).");
        }

        if (! $resolver->configured()) {
            return collect();
        }

        return $this->remember($this->cacheKey($name), $fresh, fn (): Collection => $resolver->models());
    }

    /**
     * Resolve models for an ad-hoc connector that is not declared in config/ai.php.
     *
     * Pass the underlying driver (a Lab) plus its credentials. Caching is opt-in:
     * provide a $cacheKey (e.g. "connector:{$connector->getConnectorId()}") to cache the result.
     *
     * @param  array<string, mixed>  $config  e.g. ['key' => '...', 'url' => '...']
     * @return Collection<int, AiModel>
     */
    public function driver(Lab|string $driver, array $config = [], ?string $cacheKey = null, bool $fresh = false): Collection
    {
        $entry = array_merge($config, [
            'driver' => $driver instanceof Lab ? $driver->value : $driver,
        ]);

        $resolver = $this->makeResolver($entry);

        if (! $resolver instanceof ProviderResolver) {
            $name = $driver instanceof Lab ? $driver->value : $driver;

            throw new InvalidArgumentException("No model resolver registered for driver [{$name}].");
        }

        if (! $resolver->configured()) {
            return collect();
        }

        if ($cacheKey === null) {
            return $resolver->models();
        }

        return $this->remember($this->cacheKey($cacheKey), $fresh, fn (): Collection => $resolver->models());
    }

    /**
     * Resolve models for a database-driven connector instance.
     *
     * Uses a stable per-connector cache key so repeated calls within the same
     * request hit the cache. Pass $fresh = true to bypass it.
     *
     * @return Collection<int, AiModel>
     */
    public function connector(Connector $connector, bool $fresh = false): Collection
    {
        return $this->driver(
            driver: $connector->getProvider(),
            config: array_filter([
                'key' => $connector->getApiKey(),
                'url' => $connector->getBaseUrl(),
            ]),
            cacheKey: 'connector:'.$connector->getConnectorId(),
            fresh: $fresh,
        );
    }

    /**
     * Drop cached models for one provider (or cache key), or for every provider
     * currently declared in config/ai.php.
     */
    public function refresh(Lab|string|null $provider = null): void
    {
        if ($provider !== null) {
            $name = $provider instanceof Lab ? $provider->value : $provider;
            $this->store()->forget($this->cacheKey($name));

            return;
        }

        collect((array) $this->config->get('ai.providers', []))
            ->keys()
            ->each(fn (string $name) => $this->store()->forget($this->cacheKey($name)));
    }

    /**
     * Build a resolver from a config/ai.php-style provider entry. The resolver
     * class is chosen by the entry's "driver" (Lab value); credentials and base
     * URL come from the entry, with resolver-level fallbacks (url, version)
     * filled in behind them.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function makeResolver(array $entry): ?ProviderResolver
    {
        $driver = $entry['driver'] ?? null;
        $lab = is_string($driver) ? Lab::tryFrom($driver) : null;

        if (! $lab instanceof Lab) {
            return null;
        }

        $resolverConfig = $this->resolverMap()->get($lab->value);

        if ($resolverConfig === null) {
            return null;
        }

        $config = array_merge($resolverConfig, $entry);
        unset($config['driver']);
        $config['timeout'] ??= (int) $this->config->get('ai-models.timeout', 15);

        return $this->container->make($resolverConfig['driver'], [
            'lab' => $lab,
            'config' => $config,
        ]);
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    protected function resolverMap(): Collection
    {
        return $this->resolverMap ??= collect((array) $this->config->get('ai-models.resolvers', []));
    }

    /**
     * Read a model collection from the cache, refetching (and re-caching) when
     * the entry is missing, bypassed via $fresh, or not a usable payload.
     *
     * @param  Closure(): Collection<int, AiModel>  $callback
     * @return Collection<int, AiModel>
     */
    protected function remember(string $key, bool $fresh, Closure $callback): Collection
    {
        $store = $this->store();
        $cached = $fresh ? null : $store->get($key);

        if ($this->usable($cached)) {
            return $cached;
        }

        // An unusable payload is stale or foreign — e.g. serialized by a
        // previous deploy whose classes no longer exist, or written under a
        // colliding key by other code sharing the cache. Drop it before
        // refetching so it cannot outlive a refetch that throws.
        if ($fresh || $cached !== null) {
            $store->forget($key);
        }

        $models = $callback();

        $ttl = $this->config->get('ai-models.cache.ttl');

        $ttl === null
            ? $store->forever($key, $models)
            : $store->put($key, $models, (int) $ttl);

        return $models;
    }

    /**
     * Whether a cached payload can be returned as-is. Anything that is not a
     * Collection of intact objects — notably the __PHP_Incomplete_Class
     * instances unserialize() produces when the cache holds objects of a class
     * this deployment cannot load — is treated as a cache miss.
     *
     * @phpstan-assert-if-true Collection<int, AiModel> $cached
     */
    protected function usable(mixed $cached): bool
    {
        return $cached instanceof Collection
            && ! $cached->contains(static fn ($model): bool => $model instanceof \__PHP_Incomplete_Class);
    }

    protected function store(): Repository
    {
        return $this->cache->store($this->config->get('ai-models.cache.store'));
    }

    protected function cacheKey(string $name): string
    {
        $prefix = (string) $this->config->get('ai-models.cache.prefix', 'ai-models');

        return "{$prefix}:{$name}";
    }
}
