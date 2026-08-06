<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Facades;

use Illuminate\Support\Facades\Facade;
use LmSomeco\AiModels\ModelRegistry;

/**
 * @method static \Illuminate\Support\Collection<int, \LmSomeco\AiModels\Data\AiModel> all(bool $fresh = false)
 * @method static \Illuminate\Support\Collection<int, \LmSomeco\AiModels\Data\AiModel> provider(\Laravel\Ai\Enums\Lab|string $provider, bool $fresh = false)
 * @method static \Illuminate\Support\Collection<int, \LmSomeco\AiModels\Data\AiModel> driver(\Laravel\Ai\Enums\Lab|string $driver, array<string, mixed> $config = [], ?string $cacheKey = null, bool $fresh = false)
 * @method static \Illuminate\Support\Collection<int, \LmSomeco\AiModels\Data\AiModel> connector(\LmSomeco\AiModels\Models\AiConnector $connector, bool $fresh = false)
 * @method static \Illuminate\Support\Collection<string, \LmSomeco\AiModels\Contracts\ProviderResolver> providers()
 * @method static \Illuminate\Support\Collection<int, string> configuredProviders()
 * @method static void refresh(\Laravel\Ai\Enums\Lab|string|null $provider = null)
 *
 * @see ModelRegistry
 */
class AiModels extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ModelRegistry::class;
    }
}
