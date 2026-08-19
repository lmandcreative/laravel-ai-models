<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Data\AiModel;
use LmSomeco\AiModels\Resolvers\OpenAiResolver;

it('enriches documented OpenAI model families with modalities', function () {
    $http = Http::fake([
        'api.openai.com/*' => Http::response([
            'data' => collect([
                'gpt-5',
                'gpt-5.6-sol',
                'gpt-image-1',
                'chatgpt-image-latest',
                'sora-2',
                'gpt-audio',
                'gpt-realtime-2.1',
                'gpt-realtime-whisper',
                'gpt-4o-transcribe',
                'tts-1-hd',
                'text-embedding-3-small',
                'unknown-custom-model',
            ])->map(fn (string $id): array => ['id' => $id])->all(),
        ]),
    ]);

    $models = (new OpenAiResolver($http, Lab::OpenAI, [
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]))->models()->keyBy('id');

    expect($models->get('gpt-5'))->toBeInstanceOf(AiModel::class)
        ->and($models->get('gpt-5')->modalities)->toBe(['text', 'image'])
        ->and($models->get('gpt-5')->outputModalities)->toBe(['text'])
        ->and($models->get('gpt-5.6-sol')->modalities)->toBe(['text', 'image'])
        ->and($models->get('gpt-image-1')->outputModalities)->toBe(['image'])
        ->and($models->get('chatgpt-image-latest')->outputModalities)->toBe(['image'])
        ->and($models->get('sora-2')->outputModalities)->toBe(['video', 'audio'])
        ->and($models->get('gpt-audio')->modalities)->toBe(['text', 'audio'])
        ->and($models->get('gpt-audio')->outputModalities)->toBe(['text', 'audio'])
        ->and($models->get('gpt-realtime-2.1')->outputModalities)->toBe(['text', 'audio'])
        ->and($models->get('gpt-realtime-whisper')->outputModalities)->toBe(['text'])
        ->and($models->get('gpt-4o-transcribe')->modalities)->toBe(['text', 'audio'])
        ->and($models->get('gpt-4o-transcribe')->outputModalities)->toBe(['text'])
        ->and($models->get('tts-1-hd')->outputModalities)->toBe(['audio'])
        ->and($models->get('text-embedding-3-small')->outputModalities)->toBe(['embedding'])
        ->and($models->get('unknown-custom-model')->modalities)->toBe([])
        ->and($models->get('unknown-custom-model')->outputModalities)->toBe([]);
});

it('inherits modalities from the base model for fine-tuned models', function () {
    $http = Http::fake([
        'api.openai.com/*' => Http::response([
            'data' => [['id' => 'ft:gpt-4o-mini-2024-07-18:acme:support:abc123']],
        ]),
    ]);

    $model = (new OpenAiResolver($http, Lab::OpenAI, [
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]))->models()->first();

    expect($model->modalities)->toBe(['text', 'image'])
        ->and($model->outputModalities)->toBe(['text']);
});
