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
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Contracts\ProviderResolver;
use LmSomeco\AiModels\Data\AiModel;
use LmSomeco\AiModels\Enums\ModelRole;
use ReflectionProperty;

class ModelRegistry
{
    /**
     * Bumped whenever AiModel changes shape incompatibly, so payloads cached
     * by an older major (which may not even unserialize — e.g. 1.x stored a
     * Lab in the now string-typed $provider) are never read back.
     */
    protected const CACHE_VERSION = 'v2';

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
        protected AiManager $ai,
    ) {}

    /**
     * Every provider entry in config/ai.php whose driver has a resolver, built
     * into resolver instances and keyed by the provider name.
     *
     * Providers are instantiated through laravel/ai itself, and only when
     * their driver (a string or Lab case) has a resolver — e.g. Bedrock never
     * is.
     *
     * @return Collection<string, ProviderResolver>
     */
    public function providers(): Collection
    {
        return collect(array_keys((array) $this->config->get('ai.providers', [])))
            ->mapWithKeys(fn (string $name): array => [$name => $this->hasResolverFor($this->driverOf($name))
                ? $this->makeResolver($this->ai->instance($name))
                : null])
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
     * The models for a single provider.
     *
     * Pass a config/ai.php provider name (or Lab), or any laravel/ai Provider
     * instance — including one built on demand with Ai::build(). Results are
     * cached under the provider's name; the SDK's model roles (default,
     * cheapest, smartest, ...) are applied after the cache read so config
     * changes to "models.*" take effect immediately.
     *
     * @return Collection<int, AiModel>
     */
    public function provider(Provider|Lab|string $provider, bool $fresh = false): Collection
    {
        $resolver = $this->resolverFor($provider);

        if (! $resolver->configured()) {
            return collect();
        }

        $instance = $resolver->provider();
        $roles = ModelRole::assignments($instance);

        return $this->remember($this->cacheKey($instance->name()), $fresh, fn (): Collection => $resolver->models())
            ->map(fn (AiModel $model): AiModel => isset($roles[$model->id]) ? $model->withRoles($roles[$model->id]) : $model)
            ->values();
    }

    /**
     * The listed model holding the given role on a provider (by default, its
     * default text model), or null when the provider does not list it.
     */
    public function default(Provider|Lab|string $provider, ModelRole $role = ModelRole::Text): ?AiModel
    {
        return $this->provider($provider)->first(fn (AiModel $model): bool => $model->is($role));
    }

    /**
     * Drop cached models for one provider, or for every provider currently
     * declared in config/ai.php.
     */
    public function refresh(Provider|Lab|string|null $provider = null): void
    {
        if ($provider !== null) {
            $this->store()->forget($this->cacheKey($this->nameOf($provider)));

            return;
        }

        collect((array) $this->config->get('ai.providers', []))
            ->keys()
            ->each(fn (string $name) => $this->store()->forget($this->cacheKey($name)));
    }

    protected function resolverFor(Provider|Lab|string $provider): ProviderResolver
    {
        if (! $provider instanceof Provider) {
            $name = $this->nameOf($provider);

            if (! array_key_exists($name, (array) $this->config->get('ai.providers', []))) {
                throw new InvalidArgumentException("No provider named [{$name}] configured in config('ai.providers').");
            }

            $driver = $this->driverOf($name);

            if (! $this->hasResolverFor($driver)) {
                throw new InvalidArgumentException("No model resolver registered for provider [{$name}] (driver: {$driver}).");
            }

            $provider = $this->ai->instance($name);
        }

        return $this->makeResolver($provider)
            ?? throw new InvalidArgumentException("No model resolver registered for provider [{$provider->name()}] (driver: {$provider->driver()}).");
    }

    /**
     * Build the resolver for a laravel/ai provider instance. The resolver
     * class is chosen by the provider's driver; the remaining entry keys are
     * passed to it as defaults (url, version, timeout, ...).
     */
    protected function makeResolver(Provider $provider): ?ProviderResolver
    {
        $resolverConfig = $this->resolverMap()->get($provider->driver());

        if ($resolverConfig === null || ! isset($resolverConfig['resolver'])) {
            return null;
        }

        $config = $resolverConfig;
        unset($config['resolver']);
        $config['timeout'] ??= (int) $this->config->get('ai-models.timeout', 15);

        return $this->container->make($resolverConfig['resolver'], [
            'provider' => $provider,
            'config' => $config,
        ]);
    }

    protected function hasResolverFor(string $driver): bool
    {
        return isset($this->resolverMap()->get($driver)['resolver']);
    }

    /**
     * The driver of a config/ai.php provider entry, as a string ("" when the
     * entry has none). laravel/ai accepts a Lab case as well as its value.
     */
    protected function driverOf(string $name): string
    {
        $entry = ((array) $this->config->get('ai.providers', []))[$name] ?? null;
        $driver = is_array($entry) ? ($entry['driver'] ?? null) : null;

        return match (true) {
            $driver instanceof Lab => $driver->value,
            is_string($driver) => $driver,
            default => '',
        };
    }

    protected function nameOf(Provider|Lab|string $provider): string
    {
        return match (true) {
            $provider instanceof Provider => $provider->name(),
            $provider instanceof Lab => $provider->value,
            default => $provider,
        };
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
     * this deployment cannot load, or AiModels missing a property added since
     * they were cached — is treated as a cache miss.
     *
     * @phpstan-assert-if-true Collection<int, AiModel> $cached
     */
    protected function usable(mixed $cached): bool
    {
        $driver = new ReflectionProperty(AiModel::class, 'driver');

        return $cached instanceof Collection
            && ! $cached->contains(static fn ($model): bool => ! $model instanceof AiModel
                || ! $driver->isInitialized($model));
    }

    protected function store(): Repository
    {
        return $this->cache->store($this->config->get('ai-models.cache.store'));
    }

    protected function cacheKey(string $name): string
    {
        $prefix = (string) $this->config->get('ai-models.cache.prefix', 'ai-models');

        return $prefix.':'.static::CACHE_VERSION.':'.$name;
    }
}
