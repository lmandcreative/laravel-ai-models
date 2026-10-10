<?php

declare(strict_types=1);

use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Tests\DatabaseTestCase;
use LmSomeco\AiModels\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(DatabaseTestCase::class)->in('Database');

/**
 * Build an on-demand laravel/ai provider (named "test-{driver}" unless given).
 *
 * @param  array<string, mixed>  $config  e.g. ['key' => '...', 'url' => '...']
 */
function sdkProvider(Lab $driver, array $config = [], ?string $name = null): Provider
{
    return app(AiManager::class)->build(array_merge($config, [
        'name' => $name ?? 'test-'.$driver->value,
        'driver' => $driver->value,
    ]));
}
