<?php

declare(strict_types=1);

use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Data\AiModel;
use LmSomeco\AiModels\Enums\ModelRole;

it('normalizes to an array with the provider name and driver value', function () {
    $model = new AiModel(
        provider: 'openai',
        driver: Lab::OpenAI,
        id: 'gpt-4o',
        name: 'GPT-4o',
        contextWindow: 128000,
        maxOutputTokens: 16384,
        modalities: ['text', 'image'],
        outputModalities: ['text'],
        createdAt: new DateTimeImmutable('2024-05-13T00:00:00Z'),
        roles: [ModelRole::Text, ModelRole::SmartestText],
    );

    expect($model->toArray())->toBe([
        'provider' => 'openai',
        'driver' => 'openai',
        'id' => 'gpt-4o',
        'name' => 'GPT-4o',
        'context_window' => 128000,
        'max_output_tokens' => 16384,
        'modalities' => ['text', 'image'],
        'output_modalities' => ['text'],
        'roles' => ['text', 'text.smartest'],
        'created_at' => '2024-05-13T00:00:00+00:00',
    ]);
});

it('distinguishes the provider name from its underlying driver', function () {
    $model = new AiModel(provider: 'db-5', driver: Lab::Anthropic, id: 'claude-haiku-4-5');

    expect($model->provider)->toBe('db-5')
        ->and($model->driver)->toBe(Lab::Anthropic);
});

it('reports its roles and copies itself with new ones', function () {
    $model = new AiModel(provider: 'openai', driver: Lab::OpenAI, id: 'gpt-5-nano');
    $tagged = $model->withRoles([ModelRole::CheapestText]);

    expect($model->roles)->toBe([])
        ->and($tagged->is(ModelRole::CheapestText))->toBeTrue()
        ->and($tagged->is(ModelRole::Text))->toBeFalse()
        ->and($tagged->id)->toBe('gpt-5-nano')
        ->and($tagged->provider)->toBe('openai');
});

it('reports the modalities it accepts and generates', function () {
    $model = new AiModel(
        provider: 'openrouter',
        driver: Lab::OpenRouter,
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
        new AiModel(provider: 'openrouter', driver: Lab::OpenRouter, id: 'text', outputModalities: ['text']),
        new AiModel(provider: 'openrouter', driver: Lab::OpenRouter, id: 'image', outputModalities: ['text', 'image']),
        new AiModel(provider: 'openrouter', driver: Lab::OpenRouter, id: 'audio', outputModalities: ['audio']),
    ]);

    $supported = $models->reject(
        fn (AiModel $model): bool => $model->generates('image') || $model->generates('audio'),
    );

    expect($supported->pluck('id')->all())->toBe(['text']);
});
