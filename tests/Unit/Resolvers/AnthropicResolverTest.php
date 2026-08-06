<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Resolvers\AnthropicResolver;

it('authenticates with x-api-key and the anthropic-version header', function () {
    $http = Http::fake([
        'api.anthropic.com/*' => Http::response([
            'data' => [
                ['id' => 'claude-haiku-4-5', 'display_name' => 'Claude Haiku 4.5', 'created_at' => '2025-10-01T00:00:00Z'],
            ],
            'has_more' => false,
            'last_id' => 'claude-haiku-4-5',
        ]),
    ]);

    $models = (new AnthropicResolver($http, Lab::Anthropic, [
        'key' => 'sk-ant',
        'url' => 'https://api.anthropic.com/v1',
        'version' => '2023-06-01',
        'timeout' => 15,
    ]))->models();

    expect($models)->toHaveCount(1)
        ->and($models->first()->provider)->toBe(Lab::Anthropic)
        ->and($models->first()->name)->toBe('Claude Haiku 4.5')
        ->and($models->first()->createdAt)->toBeInstanceOf(DateTimeImmutable::class);

    Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'sk-ant')
        && $request->hasHeader('anthropic-version', '2023-06-01'));
});

it('follows pagination using has_more and last_id', function () {
    $http = Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push([
                'data' => [['id' => 'claude-3-haiku', 'display_name' => 'Claude 3 Haiku']],
                'has_more' => true,
                'last_id' => 'claude-3-haiku',
            ])
            ->push([
                'data' => [['id' => 'claude-4-sonnet', 'display_name' => 'Claude 4 Sonnet']],
                'has_more' => false,
                'last_id' => 'claude-4-sonnet',
            ]),
    ]);

    $models = (new AnthropicResolver($http, Lab::Anthropic, [
        'key' => 'sk-ant',
        'url' => 'https://api.anthropic.com/v1',
        'timeout' => 15,
    ]))->models();

    expect($models)->toHaveCount(2)
        ->and($models->pluck('id')->all())->toBe(['claude-3-haiku', 'claude-4-sonnet']);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'after_id=claude-3-haiku'));
});
