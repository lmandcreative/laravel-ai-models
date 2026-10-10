<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\ModelRegistry;

it('resolves a runtime-injected config provider by its driver', function () {
    // A "db-{id}" provider injected into config at runtime, whose driver (not
    // its name) selects the resolver.
    config()->set('ai.providers.db-9f3a', [
        'driver' => 'openai',
        'key' => 'sk-from-db',
        'url' => 'https://proxy.acme.internal/v1',
    ]);

    Http::fake(['proxy.acme.internal/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $models = app(ModelRegistry::class)->provider('db-9f3a');

    expect($models)->toHaveCount(1)
        ->and($models->first()->id)->toBe('gpt-4o')
        ->and($models->first()->provider)->toBe('db-9f3a')
        ->and($models->first()->driver)->toBe(Lab::OpenAI);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://proxy.acme.internal/v1/models')
        && $request->hasHeader('Authorization', 'Bearer sk-from-db'));
});

it('includes runtime config providers in all() and configuredProviders()', function () {
    config()->set('ai.providers', [
        'db-9f3a' => ['driver' => 'openai', 'key' => 'sk-db', 'url' => 'https://proxy.acme.internal/v1'],
        'db-anth' => ['driver' => 'anthropic', 'key' => 'sk-ant', 'url' => 'https://api.anthropic.com/v1'],
    ]);

    Http::fake([
        'proxy.acme.internal/*' => Http::response(['data' => [['id' => 'gpt-4o']]]),
        'api.anthropic.com/*' => Http::response(['data' => [['id' => 'claude-haiku-4-5']], 'has_more' => false, 'last_id' => 'claude-haiku-4-5']),
    ]);

    $registry = app(ModelRegistry::class);

    expect($registry->configuredProviders()->all())->toBe(['db-9f3a', 'db-anth'])
        ->and($registry->all()->pluck('id')->sort()->values()->all())
        ->toBe(['claude-haiku-4-5', 'gpt-4o']);
});

it('lists models for an Ai::build() provider without touching config', function () {
    Http::fake(['proxy.acme.internal/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $provider = Ai::build([
        'name' => 'tenant-42',
        'driver' => 'openai',
        'key' => 'sk-adhoc',
        'url' => 'https://proxy.acme.internal/v1',
    ]);

    $models = app(ModelRegistry::class)->provider($provider);

    expect($models)->toHaveCount(1)
        ->and($models->first()->provider)->toBe('tenant-42')
        ->and($models->first()->driver)->toBe(Lab::OpenAI)
        ->and(config('ai.providers'))->toBe([])
        ->and(Cache::has('ai-models:v2:tenant-42'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-adhoc'));
});

it('caches an anonymous Ai::build() provider under its generated name', function () {
    Http::fake(['proxy.acme.internal/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $config = ['driver' => 'openai', 'key' => 'sk-adhoc', 'url' => 'https://proxy.acme.internal/v1'];

    app(ModelRegistry::class)->provider(Ai::build($config));
    app(ModelRegistry::class)->provider(Ai::build($config));

    Http::assertSentCount(1);
});

it('returns nothing for an Ai::build() provider built without a key', function () {
    Http::fake();

    $provider = Ai::build(['name' => 'keyless', 'driver' => 'openai']);

    expect(app(ModelRegistry::class)->provider($provider))->toBeEmpty();

    Http::assertNothingSent();
});

it('throws when an Ai::build() provider has no resolver', function () {
    app(ModelRegistry::class)->provider(Ai::build(['name' => 'g', 'driver' => 'gemini', 'key' => 'g']));
})->throws(InvalidArgumentException::class, 'No model resolver registered for provider [g] (driver: gemini)');
