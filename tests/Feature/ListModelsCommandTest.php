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

it('reports an unreachable provider without a stack trace', function () {
    Http::fake(['api.openai.com/*' => Http::response([], 429)]);

    $this->artisan('ai:models')
        ->expectsOutputToContain('Application rate limited by AI provider [openai].')
        ->assertFailed();
});

it('shows the provider name, driver and roles in the table', function () {
    config()->set('ai.providers.openai.models.text.default', 'gpt-4o');
    Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $this->artisan('ai:models')
        ->expectsTable(
            ['Provider', 'Driver', 'Model ID', 'Name', 'Context', 'Roles', 'Created'],
            [['openai', 'openai', 'gpt-4o', 'gpt-4o', '—', 'text', '—']],
        )
        ->assertSuccessful();
});
