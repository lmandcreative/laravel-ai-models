<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\ConnectorManager;
use LmSomeco\AiModels\ModelRegistry;
use LmSomeco\AiModels\Models\AiConnector;

// ---------------------------------------------------------------------------
// ConnectorManager::resolve()
// ---------------------------------------------------------------------------

it('resolves the default connector', function () {
    AiConnector::create([
        'name' => 'Default',
        'provider' => 'openai',
        'api_key' => 'sk-test',
        'is_active' => true,
        'is_default' => true,
        'sort_order' => 0,
    ]);

    $manager = new ConnectorManager(app(ModelRegistry::class));

    expect($manager->resolve()->name)->toBe('Default');
});

it('resolves by id', function () {
    $created = AiConnector::create([
        'name' => 'Named',
        'provider' => 'anthropic',
        'api_key' => 'sk-ant',
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 0,
    ]);

    $manager = new ConnectorManager(app(ModelRegistry::class));

    expect($manager->resolve($created->id)->id)->toBe($created->id);
});

it('falls back to the first active connector when no default is set', function () {
    AiConnector::create([
        'name' => 'First', 'provider' => 'openai', 'api_key' => 'sk-1',
        'is_active' => true, 'is_default' => false, 'sort_order' => 1,
    ]);
    AiConnector::create([
        'name' => 'Second', 'provider' => 'openai', 'api_key' => 'sk-2',
        'is_active' => true, 'is_default' => false, 'sort_order' => 2,
    ]);

    $manager = new ConnectorManager(app(ModelRegistry::class));

    expect($manager->resolve()->name)->toBe('First');
});

it('throws ModelNotFoundException when no active connector exists', function () {
    $manager = new ConnectorManager(app(ModelRegistry::class));
    $manager->resolve();
})->throws(ModelNotFoundException::class);

// ---------------------------------------------------------------------------
// ConnectorManager::configure()
// ---------------------------------------------------------------------------

it('injects the connector credentials into config at runtime', function () {
    $connector = AiConnector::create([
        'name' => 'Proxy',
        'provider' => 'openai',
        'api_key' => 'sk-proxy',
        'base_url' => 'https://proxy.test/v1',
        'is_active' => true,
        'is_default' => true,
        'sort_order' => 0,
    ]);

    $manager = new ConnectorManager(app(ModelRegistry::class));
    $providerKey = $manager->configure($connector);

    expect($providerKey)->toBe('db-'.$connector->id)
        ->and(config("ai.providers.{$providerKey}.driver"))->toBe('openai')
        ->and(config("ai.providers.{$providerKey}.key"))->toBe('sk-proxy')
        ->and(config("ai.providers.{$providerKey}.url"))->toBe('https://proxy.test/v1');
});

it('omits url from the injected config when base_url is null', function () {
    $connector = AiConnector::create([
        'name' => 'Plain',
        'provider' => 'openai',
        'api_key' => 'sk-plain',
        'is_active' => true,
        'is_default' => true,
        'sort_order' => 0,
    ]);

    $providerKey = (new ConnectorManager(app(ModelRegistry::class)))->configure($connector);

    expect(config("ai.providers.{$providerKey}"))->not->toHaveKey('url');
});

// ---------------------------------------------------------------------------
// ConnectorManager::models() / ModelRegistry::connector()
// ---------------------------------------------------------------------------

it('lists models for a connector via models()', function () {
    $connector = AiConnector::create([
        'name' => 'Proxy',
        'provider' => 'openai',
        'api_key' => 'sk-proxy',
        'base_url' => 'https://proxy.test/v1',
        'is_active' => true,
        'is_default' => true,
        'sort_order' => 0,
    ]);

    Http::fake(['proxy.test/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $models = (new ConnectorManager(app(ModelRegistry::class)))->models($connector);

    expect($models)->toHaveCount(1)
        ->and($models->first()->id)->toBe('gpt-4o')
        ->and($models->first()->provider)->toBe(Lab::OpenAI);
});

// ---------------------------------------------------------------------------
// Custom model class
// ---------------------------------------------------------------------------

it('accepts a custom connectorModel class', function () {
    AiConnector::create([
        'name' => 'Custom',
        'provider' => 'openai',
        'api_key' => 'sk-custom',
        'is_active' => true,
        'is_default' => true,
        'sort_order' => 0,
    ]);

    $manager = new ConnectorManager(
        registry: app(ModelRegistry::class),
        connectorModel: AiConnector::class,  // would be App\Models\AiConnector in a real app
    );

    expect($manager->resolve()->name)->toBe('Custom');
});

// ---------------------------------------------------------------------------
// Enabled/disabled via config
// ---------------------------------------------------------------------------

it('is registered in the container when connectors.enabled is true', function () {
    // DatabaseTestCase already sets connectors.enabled = true and re-runs register().
    expect(app(ConnectorManager::class))->toBeInstanceOf(ConnectorManager::class);
});
