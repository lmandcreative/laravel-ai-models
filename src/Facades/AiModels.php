<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Facades;

use Illuminate\Support\Facades\Facade;
use LmSomeco\AiModels\ModelRegistry;

/**
 * @method static \Illuminate\Support\Collection<int, \LmSomeco\AiModels\Data\AiModel> all(bool $fresh = false)
 * @method static \Illuminate\Support\Collection<int, \LmSomeco\AiModels\Data\AiModel> provider(\Laravel\Ai\Contracts\Providers\Provider|\Laravel\Ai\Enums\Lab|string $provider, bool $fresh = false)
 * @method static \LmSomeco\AiModels\Data\AiModel|null default(\Laravel\Ai\Contracts\Providers\Provider|\Laravel\Ai\Enums\Lab|string $provider, \LmSomeco\AiModels\Enums\ModelRole $role = \LmSomeco\AiModels\Enums\ModelRole::Text)
 * @method static \Illuminate\Support\Collection<string, \LmSomeco\AiModels\Contracts\ProviderResolver> providers()
 * @method static \Illuminate\Support\Collection<int, string> configuredProviders()
 * @method static void refresh(\Laravel\Ai\Contracts\Providers\Provider|\Laravel\Ai\Enums\Lab|string|null $provider = null)
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
