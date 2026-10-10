<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use LmSomeco\AiModels\Facades\AiModels;
use LmSomeco\AiModels\ModelRegistry;
use LmSomeco\AiModels\Resolvers\AnthropicResolver;
use LmSomeco\AiModels\Resolvers\OpenAiCompatibleResolver;

function failingOpenAiResolver(mixed $response): OpenAiCompatibleResolver
{
    return new OpenAiCompatibleResolver(
        Http::fake(['api.openai.com/*' => $response]),
        sdkProvider(Lab::OpenAI, ['key' => 'sk', 'url' => 'https://api.openai.com/v1'], 'primary'),
    );
}

it('names the provider in the failover exception', function () {
    expect(fn () => failingOpenAiResolver(Http::response([], 429))->models())
        ->toThrow(RateLimitedException::class, 'Application rate limited by AI provider [primary].');
});

it('rethrows HTTP failures as laravel/ai failover exceptions', function (int $status, string $exception) {
    expect(fn () => failingOpenAiResolver(Http::response([], $status))->models())->toThrow($exception);
})->with([
    [429, RateLimitedException::class],
    [402, InsufficientCreditsException::class],
    [503, ProviderOverloadedException::class],
    [401, RequestException::class],
]);

it('rethrows connection failures as ProviderConnectionException', function () {
    $resolver = failingOpenAiResolver(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => $resolver->models())->toThrow(ProviderConnectionException::class);
});

it('honours per-lab overloaded status codes from resolver config', function () {
    $resolver = new AnthropicResolver(
        Http::fake(['api.anthropic.com/*' => Http::response([], 529)]),
        sdkProvider(Lab::Anthropic, ['key' => 'sk-ant']),
        ['url' => 'https://api.anthropic.com/v1', 'overloaded_status_codes' => [529]],
    );

    expect(fn () => $resolver->models())->toThrow(ProviderOverloadedException::class);
});

it('uses the shipped per-lab status codes through the registry', function () {
    config()->set('ai.providers.anthropic', ['driver' => 'anthropic', 'key' => 'sk-ant']);
    Http::fake(['api.anthropic.com/*' => Http::response([], 529)]);

    expect(fn () => AiModels::provider('anthropic'))
        ->toThrow(ProviderOverloadedException::class);
});

it('treats an openai-compatible provider as configured by its URL alone', function () {
    config()->set('ai.providers.local', ['driver' => 'openai-compatible', 'url' => 'http://llm.test/v1']);
    config()->set('ai.providers.unset', ['driver' => 'openai-compatible', 'url' => null]);

    Http::fake(['llm.test/*' => Http::response(['data' => [['id' => 'qwen3']]])]);

    $registry = app(ModelRegistry::class);

    expect($registry->configuredProviders()->all())->toBe(['local'])
        ->and($registry->provider('local')->first()->driver)->toBe(Lab::OpenAICompatible);

    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
});

it('falls back to the resolver default URL when the provider sets none', function () {
    $resolver = new OpenAiCompatibleResolver(
        Http::fake(['api.groq.com/*' => Http::response(['data' => [['id' => 'llama']]])]),
        sdkProvider(Lab::Groq, ['key' => 'gsk']),
        ['url' => 'https://api.groq.com/openai/v1'],
    );

    expect($resolver->configured())->toBeTrue()
        ->and($resolver->models()->first()->id)->toBe('llama');
});
