<?php

declare(strict_types=1);

namespace LmSomeco\AiModels;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LmSomeco\AiModels\Concerns\IsConnector;
use LmSomeco\AiModels\Console\ListModelsCommand;
use LmSomeco\AiModels\Contracts\Connector;
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

        // Registered unconditionally (the singleton is lazy): gating this on
        // connectors.enabled would let the container fall back to reflection
        // auto-wiring, silently ignoring the configured model class and this
        // validation. Only the migrations are gated on the flag.
        $this->app->singleton(ConnectorManager::class, function (Container $app): ConnectorManager {
            $model = $app->make(Config::class)->get(
                'ai-models.connectors.model',
                AiConnector::class,
            );

            if (! is_string($model) || ! is_a($model, Model::class, true) || ! is_a($model, Connector::class, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The ai-models.connectors.model config value [%s] must be an Eloquent model implementing [%s]. '
                    .'Extend [%s], add the [%s] trait to a model with the standard connector columns, '
                    .'or implement the contract yourself.',
                    is_string($model) ? $model : get_debug_type($model),
                    Connector::class,
                    AiConnector::class,
                    IsConnector::class,
                ));
            }

            return new ConnectorManager(
                registry: $app->make(ModelRegistry::class),
                connectorModel: $model,
            );
        });
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
