<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\ModelRegistry;

function registry(): ModelRegistry
{
    return app(ModelRegistry::class);
}

it('lists models for a statically configured provider', function () {
    config()->set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]);

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $models = registry()->provider('openai');

    expect($models)->toHaveCount(1)
        ->and($models->first()->id)->toBe('gpt-4o')
        ->and($models->first()->provider)->toBe(Lab::OpenAI);
});

it('accepts a Lab as well as a provider name', function () {
    config()->set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]);

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    expect(registry()->provider(Lab::OpenAI))->toHaveCount(1);
});

it('aggregates every configured provider through all()', function () {
    config()->set('ai.providers', [
        'openai' => ['driver' => 'openai', 'key' => 'sk-o', 'url' => 'https://api.openai.com/v1'],
        'groq' => ['driver' => 'groq', 'key' => 'sk-g'],
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]]),
        'api.groq.com/*' => Http::response(['data' => [['id' => 'llama-3.3-70b']]]),
    ]);

    expect(registry()->all()->pluck('id')->sort()->values()->all())
        ->toBe(['gpt-4o', 'llama-3.3-70b']);
});

it('omits providers that have no key or no resolver', function () {
    config()->set('ai.providers', [
        'openai' => ['driver' => 'openai', 'key' => 'sk-o', 'url' => 'https://api.openai.com/v1'],
        'mistral' => ['driver' => 'mistral'],            // no key -> not configured
        'gemini' => ['driver' => 'gemini', 'key' => 'g'], // no resolver yet
    ]);

    Http::fake(['*' => Http::response(['data' => []])]);

    expect(registry()->configuredProviders()->all())->toBe(['openai']);
});

it('caches per provider and re-fetches only when refreshed', function () {
    config()->set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]);
    config()->set('ai-models.cache.ttl', 3600);

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    registry()->provider('openai');
    registry()->provider('openai');
    Http::assertSentCount(1);

    registry()->provider('openai', fresh: true);
    Http::assertSentCount(2);
});

it('throws for a provider that is not in config', function () {
    registry()->provider('does-not-exist');
})->throws(InvalidArgumentException::class, 'No provider named [does-not-exist]');

it('returns an empty collection for a configured-but-keyless provider', function () {
    config()->set('ai.providers.mistral', ['driver' => 'mistral']);

    expect(registry()->provider('mistral'))->toBeEmpty();
});
