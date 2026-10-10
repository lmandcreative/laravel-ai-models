<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Data\AiModel;
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
        ->and($models->first()->provider)->toBe('openai')
        ->and($models->first()->driver)->toBe(Lab::OpenAI)
        ->and($models->first()->modalities)->toBe(['text', 'image'])
        ->and($models->first()->outputModalities)->toBe(['text']);
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
        'broken' => ['key' => 'no-driver'],              // malformed entry
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

it('discards a cached payload whose class no longer exists and refetches', function () {
    config()->set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]);
    config()->set('ai-models.cache.ttl', 3600);

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    // What unserialize() yields when a cache entry was written by code (an
    // older deploy, another app on the same store) whose class we can't load.
    Cache::put('ai-models:v2:openai', unserialize('O:19:"Legacy\ModelListing":0:{}'), 3600);

    $models = registry()->provider('openai');

    expect($models)->toHaveCount(1)
        ->and($models->first()->id)->toBe('gpt-4o');

    // The healed entry is served from cache on the next call.
    registry()->provider('openai');
    Http::assertSentCount(1);
});

it('discards a cached collection containing incomplete objects and refetches', function () {
    config()->set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]);
    config()->set('ai-models.cache.ttl', 3600);

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    Cache::put('ai-models:v2:openai', collect([unserialize('O:10:"Legacy\Dto":0:{}')]), 3600);

    expect(registry()->provider('openai')->pluck('id')->all())->toBe(['gpt-4o']);
    Http::assertSentCount(1);
});

it('discards cached models missing a property added since they were cached', function () {
    config()->set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]);
    config()->set('ai-models.cache.ttl', 3600);

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $class = AiModel::class;
    $legacyModel = unserialize(sprintf('O:%d:"%s":0:{}', strlen($class), $class));

    Cache::put('ai-models:v2:openai', collect([$legacyModel]), 3600);

    expect(registry()->provider('openai')->pluck('id')->all())->toBe(['gpt-4o']);
    Http::assertSentCount(1);
});

it('never reads entries cached by 1.x, which stored a Lab in the provider property', function () {
    config()->set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]);
    config()->set('ai-models.cache.ttl', 3600);

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    // 1.x wrote to the unversioned key. Its AiModels hold a Lab in the now
    // string-typed $provider, so unserializing one throws a TypeError: the
    // entry must never be read (or even dropped, which implies a read).
    Cache::put('ai-models:openai', 'legacy-1.x-payload', 3600);

    expect(registry()->provider('openai')->pluck('id')->all())->toBe(['gpt-4o'])
        ->and(Cache::get('ai-models:openai'))->toBe('legacy-1.x-payload')
        ->and(Cache::has('ai-models:v2:openai'))->toBeTrue();
});

it('resolves a config entry whose driver is a Lab case', function () {
    config()->set('ai.providers.primary', [
        'driver' => Lab::OpenAI,
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]);

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    expect(registry()->configuredProviders()->all())->toBe(['primary'])
        ->and(registry()->provider('primary')->first()->driver)->toBe(Lab::OpenAI);
});

it('drops the cache for a single provider on refresh()', function () {
    config()->set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]);
    config()->set('ai-models.cache.ttl', 3600);

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    registry()->provider('openai');
    registry()->refresh(Lab::OpenAI);
    registry()->provider('openai');

    Http::assertSentCount(2);
});

it('throws for a provider that is not in config', function () {
    registry()->provider('does-not-exist');
})->throws(InvalidArgumentException::class, 'No provider named [does-not-exist]');

it('returns an empty collection for a configured-but-keyless provider', function () {
    config()->set('ai.providers.mistral', ['driver' => 'mistral']);

    expect(registry()->provider('mistral'))->toBeEmpty();
});

it('throws for a configured provider whose driver has no resolver', function () {
    config()->set('ai.providers.gemini', ['driver' => 'gemini', 'key' => 'g']);

    registry()->provider('gemini');
})->throws(InvalidArgumentException::class, 'No model resolver registered for provider [gemini] (driver: gemini)');
