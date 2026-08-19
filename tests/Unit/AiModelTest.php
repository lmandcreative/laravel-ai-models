<?php

declare(strict_types=1);

use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Data\AiModel;

it('normalizes to an array with the provider as its Lab value', function () {
    $model = new AiModel(
        provider: Lab::OpenAI,
        id: 'gpt-4o',
        name: 'GPT-4o',
        contextWindow: 128000,
        maxOutputTokens: 16384,
        modalities: ['text', 'image'],
        outputModalities: ['text'],
        createdAt: new DateTimeImmutable('2024-05-13T00:00:00Z'),
    );

    expect($model->toArray())->toBe([
        'provider' => 'openai',
        'id' => 'gpt-4o',
        'name' => 'GPT-4o',
        'context_window' => 128000,
        'max_output_tokens' => 16384,
        'modalities' => ['text', 'image'],
        'output_modalities' => ['text'],
        'created_at' => '2024-05-13T00:00:00+00:00',
    ]);
});

it('keeps the underlying Lab on the provider property', function () {
    $model = new AiModel(provider: Lab::Anthropic, id: 'claude-haiku-4-5');

    expect($model->provider)->toBe(Lab::Anthropic);
});

it('reports the modalities it accepts and generates', function () {
    $model = new AiModel(
        provider: Lab::OpenRouter,
        id: 'example/model',
        modalities: ['text', 'image'],
        outputModalities: ['text', 'audio'],
    );

    expect($model->accepts('image'))->toBeTrue()
        ->and($model->accepts('audio'))->toBeFalse()
        ->and($model->generates('audio'))->toBeTrue()
        ->and($model->generates('image'))->toBeFalse();
});

it('can filter out models that generate unsupported output modalities', function () {
    $models = collect([
        new AiModel(provider: Lab::OpenRouter, id: 'text', outputModalities: ['text']),
        new AiModel(provider: Lab::OpenRouter, id: 'image', outputModalities: ['text', 'image']),
        new AiModel(provider: Lab::OpenRouter, id: 'audio', outputModalities: ['audio']),
    ]);

    $supported = $models->reject(
        fn (AiModel $model): bool => $model->generates('image') || $model->generates('audio'),
    );

    expect($supported->pluck('id')->all())->toBe(['text']);
});
