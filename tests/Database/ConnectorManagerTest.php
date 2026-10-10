<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\Provider;
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

    $manager = app(ConnectorManager::class);

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

    $manager = app(ConnectorManager::class);

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

    $manager = app(ConnectorManager::class);

    expect($manager->resolve()->name)->toBe('First');
});

it('throws ModelNotFoundException when no active connector exists', function () {
    $manager = app(ConnectorManager::class);
    $manager->resolve();
})->throws(ModelNotFoundException::class);

// ---------------------------------------------------------------------------
// ConnectorManager::provider()
// ---------------------------------------------------------------------------

it('builds an on-demand laravel/ai provider for the connector', function () {
    $connector = AiConnector::create([
        'name' => 'Proxy',
        'provider' => 'openai',
        'api_key' => 'sk-proxy',
        'base_url' => 'https://proxy.test/v1',
        'is_active' => true,
        'is_default' => true,
        'sort_order' => 0,
    ]);

    $provider = app(ConnectorManager::class)->provider($connector);

    expect($provider)->toBeInstanceOf(Provider::class)
        ->and($provider->name())->toBe('db-'.$connector->id)
        ->and($provider->driver())->toBe('openai')
        ->and($provider->providerCredentials())->toBe(['key' => 'sk-proxy'])
        ->and($provider->additionalConfiguration())->toBe(['url' => 'https://proxy.test/v1'])
        ->and(config('ai.providers'))->toBe([]);

    // Resolvable by name for the rest of the operation, e.g. prompt(provider: 'db-…').
    expect(Ai::textProvider($provider->name()))->toBe($provider);
});

it('omits url from the built provider when base_url is null', function () {
    $connector = AiConnector::create([
        'name' => 'Plain',
        'provider' => 'openai',
        'api_key' => 'sk-plain',
        'is_active' => true,
        'is_default' => true,
        'sort_order' => 0,
    ]);

    $provider = app(ConnectorManager::class)->provider($connector);

    expect($provider->additionalConfiguration())->not->toHaveKey('url');
});

it('builds a provider that survives serialization with its key encrypted', function () {
    $connector = AiConnector::create([
        'name' => 'Queued',
        'provider' => 'anthropic',
        'api_key' => 'sk-secret',
        'is_active' => true,
        'is_default' => true,
        'sort_order' => 0,
    ]);

    $serialized = serialize(app(ConnectorManager::class)->provider($connector));

    // Simulate a fresh queue worker process.
    app(AiManager::class)->flushState();
    $restored = unserialize($serialized);

    expect($serialized)->not->toContain('sk-secret')
        ->and($restored->name())->toBe('db-'.$connector->id)
        ->and($restored->providerCredentials())->toBe(['key' => 'sk-secret']);
});

// ---------------------------------------------------------------------------
// ConnectorManager::models()
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

    $models = app(ConnectorManager::class)->models($connector);

    expect($models)->toHaveCount(1)
        ->and($models->first()->id)->toBe('gpt-4o')
        ->and($models->first()->provider)->toBe('db-'.$connector->id)
        ->and($models->first()->driver)->toBe(Lab::OpenAI);
});

it('caches connector models under the db-{id} provider name', function () {
    config()->set('ai-models.cache.ttl', 3600);

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

    $manager = app(ConnectorManager::class);
    $manager->models($connector);
    $manager->models($connector);
    Http::assertSentCount(1);

    app(ModelRegistry::class)->refresh('db-'.$connector->id);
    $manager->models($connector);
    Http::assertSentCount(2);
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
        ai: app(AiManager::class),
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
