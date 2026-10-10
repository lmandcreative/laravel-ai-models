<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Data\AiModel;
use LmSomeco\AiModels\Enums\ModelRole;
use LmSomeco\AiModels\Facades\AiModels;

beforeEach(function () {
    config()->set('ai.providers.anthropic', [
        'driver' => 'anthropic',
        'key' => 'sk-ant',
        'url' => 'https://api.anthropic.com/v1',
        'models' => ['text' => [
            'default' => 'claude-sonnet',
            'cheapest' => 'claude-haiku',
            'smartest' => 'claude-opus',
        ]],
    ]);

    Http::fake(['api.anthropic.com/*' => Http::response([
        'data' => [
            ['id' => 'claude-haiku'],
            ['id' => 'claude-legacy'],
            ['id' => 'claude-opus'],
            ['id' => 'claude-sonnet'],
        ],
        'has_more' => false,
    ])]);
});

it('tags models with the roles the SDK provider assigns them', function () {
    $roles = AiModels::provider('anthropic')
        ->mapWithKeys(fn (AiModel $model): array => [$model->id => $model->roles])
        ->all();

    expect($roles)->toBe([
        'claude-haiku' => [ModelRole::CheapestText],
        'claude-legacy' => [],
        'claude-opus' => [ModelRole::SmartestText],
        'claude-sonnet' => [ModelRole::Text],
    ]);
});

it('returns the model holding a role via default()', function () {
    expect(AiModels::default('anthropic')?->id)->toBe('claude-sonnet')
        ->and(AiModels::default(Lab::Anthropic, ModelRole::CheapestText)?->id)->toBe('claude-haiku')
        ->and(AiModels::default('anthropic', ModelRole::Embeddings))->toBeNull();
});

it('applies roles after the cache so config changes need no refresh', function () {
    config()->set('ai-models.cache.ttl', 3600);

    expect(AiModels::default('anthropic')?->id)->toBe('claude-sonnet');

    config()->set('ai.providers.anthropic.models.text.default', 'claude-opus');
    app(AiManager::class)->forgetInstance('anthropic');

    expect(AiModels::default('anthropic')?->id)->toBe('claude-opus');

    Http::assertSentCount(1);
});

it('tags every capability default on a multi-capability provider', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => [
        ['id' => 'gpt-5.5'],
        ['id' => 'gpt-image-2'],
        ['id' => 'text-embedding-3-small'],
    ]])]);

    $provider = Ai::build([
        'name' => 'oa',
        'driver' => 'openai',
        'key' => 'sk',
        'url' => 'https://api.openai.com/v1',
        'models' => [
            'text' => ['default' => 'gpt-5.5', 'smartest' => 'gpt-5.5'],
            'image' => ['default' => 'gpt-image-2'],
            'embeddings' => ['default' => 'text-embedding-3-small'],
        ],
    ]);

    $models = AiModels::provider($provider)->keyBy('id');

    expect($models['gpt-5.5']->roles)->toBe([ModelRole::Text, ModelRole::SmartestText])
        ->and($models['gpt-image-2']->is(ModelRole::Image))->toBeTrue()
        ->and($models['text-embedding-3-small']->is(ModelRole::Embeddings))->toBeTrue();
});

it('skips roles an openai-compatible provider leaves unconfigured instead of throwing', function () {
    Http::fake(['llm.acme.internal/*' => Http::response(['data' => [['id' => 'qwen3']]])]);

    $provider = Ai::build([
        'name' => 'local',
        'driver' => 'openai-compatible',
        'url' => 'https://llm.acme.internal/v1',
    ]);

    // OpenAiCompatibleProvider::defaultTextModel() throws without models.text.default.
    expect(ModelRole::assignments($provider))->toBe([])
        ->and(AiModels::provider($provider)->first()->roles)->toBe([]);
});
