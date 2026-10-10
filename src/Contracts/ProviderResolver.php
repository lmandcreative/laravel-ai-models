<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Contracts;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Providers\Provider;
use LmSomeco\AiModels\Data\AiModel;

interface ProviderResolver
{
    /**
     * The laravel/ai provider instance this resolver lists models for.
     */
    public function provider(): Provider;

    /**
     * Whether this resolver has everything it needs (credentials, base URL) to run.
     */
    public function configured(): bool;

    /**
     * Fetch the live list of models for this provider.
     *
     * @return Collection<int, AiModel>
     */
    public function models(): Collection;
}
