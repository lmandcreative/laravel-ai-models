<?php

declare(strict_types=1);

namespace LmSomeco\AiModels;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use LmSomeco\AiModels\Console\ListModelsCommand;
use LmSomeco\AiModels\Models\AiConnector;

class AiModelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-models.php', 'ai-models');

        $this->app->singleton(ModelRegistry::class, function (Container $app): ModelRegistry {
            return new ModelRegistry(
                container: $app,
                cache: $app->make(CacheFactory::class),
                config: $app->make(Config::class),
            );
        });

        if ($this->app->make(Config::class)->get('ai-models.connectors.enabled', false)) {
            $this->app->singleton(ConnectorManager::class, function (Container $app): ConnectorManager {
                /** @var class-string<AiConnector> $model */
                $model = $app->make(Config::class)->get(
                    'ai-models.connectors.model',
                    AiConnector::class,
                );

                return new ConnectorManager(
                    registry: $app->make(ModelRegistry::class),
                    connectorModel: $model,
                );
            });
        }
    }

    public function boot(): void
    {
        if ($this->app->make(Config::class)->get('ai-models.connectors.enabled', false)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-models.php' => $this->app->configPath('ai-models.php'),
            ], 'ai-models-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_ai_connectors_table.php' => database_path('migrations/'.date('Y_m_d_His').'_create_ai_connectors_table.php'),
            ], 'ai-models-migrations');

            $this->commands([
                ListModelsCommand::class,
            ]);
        }
    }
}
