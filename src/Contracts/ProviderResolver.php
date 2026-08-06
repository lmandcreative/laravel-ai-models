<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Contracts;

use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Data\AiModel;

interface ProviderResolver
{
    /**
     * The laravel/ai provider this resolver answers to.
     */
    public function provider(): Lab;

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
