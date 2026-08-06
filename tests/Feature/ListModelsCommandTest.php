<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'url' => 'https://api.openai.com/v1',
    ]);
});

it('prints a table of models', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $this->artisan('ai:models')
        ->expectsOutputToContain('gpt-4o')
        ->assertSuccessful();
});

it('can filter to a single provider', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $this->artisan('ai:models', ['provider' => 'openai'])->assertSuccessful();
});

it('fails for an unknown provider', function () {
    $this->artisan('ai:models', ['provider' => 'nope'])->assertFailed();
});

it('warns when a provider has no configured key', function () {
    config()->set('ai.providers', ['mistral' => ['driver' => 'mistral']]);

    $this->artisan('ai:models')
        ->expectsOutputToContain('No models found')
        ->assertSuccessful();
});
