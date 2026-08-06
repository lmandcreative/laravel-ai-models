<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Tests;

use LmSomeco\AiModels\AiModelsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiModelsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        // Each test starts with no providers and registers exactly what it needs
        // into config('ai.providers'), mirroring how laravel/ai is configured.
        $app['config']->set('ai.providers', []);
    }
}
