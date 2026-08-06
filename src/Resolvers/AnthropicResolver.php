<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Resolvers;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Contracts\ProviderResolver;
use LmSomeco\AiModels\Data\AiModel;

/**
 * Anthropic exposes `GET /v1/models` but authenticates with an `x-api-key`
 * header plus an `anthropic-version` header, returns `display_name` and an
 * ISO-8601 `created_at`, and paginates with `after_id` / `has_more`.
 */
class AnthropicResolver implements ProviderResolver
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected HttpFactory $http,
        protected Lab $lab,
        protected array $config,
    ) {}

    public function provider(): Lab
    {
        return $this->lab;
    }

    public function configured(): bool
    {
        return filled($this->config['key'] ?? null);
    }

    public function models(): Collection
    {
        $baseUrl = rtrim((string) ($this->config['url'] ?? 'https://api.anthropic.com/v1'), '/');
        $models = collect();
        $query = ['limit' => 1000];

        do {
            $response = $this->http
                ->baseUrl($baseUrl)
                ->withHeaders([
                    'x-api-key' => (string) $this->config['key'],
                    'anthropic-version' => (string) ($this->config['version'] ?? '2023-06-01'),
                ])
                ->acceptJson()
                ->timeout((int) ($this->config['timeout'] ?? 15))
                ->get('/models', $query)
                ->throw();

            foreach ($response->json('data', []) as $model) {
                if (empty($model['id'])) {
                    continue;
                }

                $models->push(new AiModel(
                    provider: $this->provider(),
                    id: (string) $model['id'],
                    name: $model['display_name'] ?? (string) $model['id'],
                    createdAt: isset($model['created_at'])
                        ? new DateTimeImmutable((string) $model['created_at'])
                        : null,
                    raw: $model,
                ));
            }

            $hasMore = (bool) $response->json('has_more', false);
            $query['after_id'] = $response->json('last_id');
        } while ($hasMore && filled($query['after_id']));

        return $models->sortBy('id')->values();
    }
}
