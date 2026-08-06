<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Resolvers\OpenRouterResolver;

it('maps OpenRouter rich metadata onto the model', function () {
    $http = Http::fake([
        'openrouter.ai/*' => Http::response([
            'data' => [
                [
                    'id' => 'openai/gpt-4o',
                    'name' => 'OpenAI: GPT-4o',
                    'context_length' => 128000,
                    'top_provider' => ['max_completion_tokens' => 16384],
                    'architecture' => ['input_modalities' => ['text', 'image']],
                ],
            ],
        ]),
    ]);

    $model = (new OpenRouterResolver($http, Lab::OpenRouter, [
        'url' => 'https://openrouter.ai/api/v1',
        'timeout' => 15,
    ]))->models()->first();

    expect($model->id)->toBe('openai/gpt-4o')
        ->and($model->name)->toBe('OpenAI: GPT-4o')
        ->and($model->contextWindow)->toBe(128000)
        ->and($model->maxOutputTokens)->toBe(16384)
        ->and($model->modalities)->toBe(['text', 'image']);
});

it('is configured without an API key because listing is public', function () {
    $resolver = new OpenRouterResolver(Http::fake(), Lab::OpenRouter, [
        'url' => 'https://openrouter.ai/api/v1',
    ]);

    expect($resolver->configured())->toBeTrue();
});
