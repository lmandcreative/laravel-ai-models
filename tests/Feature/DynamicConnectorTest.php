<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\ModelRegistry;

it('resolves a runtime-injected db connector by its driver', function () {
    // Mirror App\Services\AiService::configureConnector(): a "db-{id}" provider
    // injected into config at runtime, whose driver (not its name) selects the resolver.
    config()->set('ai.providers.db-9f3a', [
        'driver' => 'openai',
        'key' => 'sk-from-db',
        'url' => 'https://proxy.acme.internal/v1',
    ]);

    Http::fake(['proxy.acme.internal/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $models = app(ModelRegistry::class)->provider('db-9f3a');

    expect($models)->toHaveCount(1)
        ->and($models->first()->id)->toBe('gpt-4o')
        ->and($models->first()->provider)->toBe(Lab::OpenAI); // underlying driver lab

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://proxy.acme.internal/v1/models')
        && $request->hasHeader('Authorization', 'Bearer sk-from-db'));
});

it('includes runtime connectors in all() and configuredProviders()', function () {
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

it('resolves an ad-hoc connector via driver() without touching config', function () {
    Http::fake(['proxy.acme.internal/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $models = app(ModelRegistry::class)->driver(
        Lab::OpenAI,
        ['key' => 'sk-adhoc', 'url' => 'https://proxy.acme.internal/v1'],
        cacheKey: 'connector:9f3a',
    );

    expect($models)->toHaveCount(1)->and($models->first()->id)->toBe('gpt-4o');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-adhoc'));
});

it('throws when an ad-hoc driver has no resolver', function () {
    app(ModelRegistry::class)->driver(Lab::Gemini, ['key' => 'g']);
})->throws(InvalidArgumentException::class, 'No model resolver registered for driver [gemini]');
