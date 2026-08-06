<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use LmSomeco\AiModels\Data\AiModel;
use LmSomeco\AiModels\ModelRegistry;

class ListModelsCommand extends Command
{
    protected $signature = 'ai:models
        {provider? : Limit output to a single provider (Lab value, e.g. openai)}
        {--refresh : Bypass and rebuild the cache}
        {--json : Output raw JSON instead of a table}';

    protected $description = 'List the currently-available models for each configured AI provider';

    public function handle(ModelRegistry $registry): int
    {
        $provider = $this->argument('provider');
        $fresh = (bool) $this->option('refresh');

        if ($provider !== null && ! is_string($provider)) {
            $this->error('The provider argument must be a single value.');

            return self::FAILURE;
        }

        try {
            $models = $provider !== null
                ? $registry->provider($provider, $fresh)
                : $registry->all($fresh);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->line('Configured providers: '.$registry->configuredProviders()->implode(', '));

            return self::FAILURE;
        }

        if ($models->isEmpty()) {
            $this->warn('No models found. Check that the relevant API keys are set in config/ai.php.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($models->map->toArray()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Provider', 'Model ID', 'Name', 'Context', 'Created'],
            $models->map(fn (AiModel $model): array => [
                $model->provider->value,
                $model->id,
                $model->name,
                $model->contextWindow !== null ? number_format($model->contextWindow) : '—',
                $model->createdAt?->format('Y-m-d') ?? '—',
            ])->all(),
        );

        $this->newLine();
        $this->info(sprintf(
            '%d models across %d provider(s).',
            $models->count(),
            $models->pluck('provider')->unique()->count(),
        ));

        return self::SUCCESS;
    }
}
