<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Resolvers\OpenAiCompatibleResolver;

function openAiResolver(array $config = []): OpenAiCompatibleResolver
{
    $http = Http::fake([
        'api.openai.com/*' => Http::response([
            'data' => [
                ['id' => 'gpt-4o-mini', 'created' => 1716000000],
                ['id' => 'gpt-4o', 'created' => 1715000000],
                ['name' => 'missing-id'],
            ],
        ]),
    ]);

    return new OpenAiCompatibleResolver($http, Lab::OpenAI, array_merge([
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
        'timeout' => 15,
    ], $config));
}

it('lists and normalizes models from an OpenAI-style endpoint', function () {
    $models = openAiResolver()->models();

    // Two valid models (the id-less row is dropped) and sorted by id.
    expect($models)->toHaveCount(2)
        ->and($models->pluck('id')->all())->toBe(['gpt-4o', 'gpt-4o-mini'])
        ->and($models->first()->provider)->toBe(Lab::OpenAI)
        ->and($models->first()->createdAt)->toBeInstanceOf(DateTimeImmutable::class);
});

it('sends a bearer token to the configured base URL', function () {
    openAiResolver()->models();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-test')
        && str_starts_with($request->url(), 'https://api.openai.com/v1/models'));
});

it('reports as unconfigured when no key is present', function () {
    $http = Http::fake();
    $resolver = new OpenAiCompatibleResolver($http, Lab::OpenAI, ['url' => 'https://api.openai.com/v1']);

    expect($resolver->configured())->toBeFalse();
});
