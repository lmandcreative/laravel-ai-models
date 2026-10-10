<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Resolvers;

use DateTimeImmutable;
use Illuminate\Support\Collection;
use LmSomeco\AiModels\Data\AiModel;

/**
 * Anthropic exposes `GET /v1/models` but authenticates with an `x-api-key`
 * header plus an `anthropic-version` header, returns `display_name` and an
 * ISO-8601 `created_at`, and paginates with `after_id` / `has_more`.
 */
class AnthropicResolver extends Resolver
{
    protected function fetch(): Collection
    {
        /** @var Collection<int, AiModel> $models */
        $models = collect();
        $query = ['limit' => 1000];

        do {
            $response = $this->request()
                ->withHeaders([
                    'x-api-key' => (string) $this->apiKey(),
                    'anthropic-version' => (string) ($this->config['version'] ?? '2023-06-01'),
                ])
                ->get('/models', $query)
                ->throw();

            foreach ($response->json('data', []) as $model) {
                if (empty($model['id'])) {
                    continue;
                }

                $models->push($this->newModel(
                    id: (string) $model['id'],
                    name: $model['display_name'] ?? (string) $model['id'],
                    contextWindow: isset($model['max_input_tokens'])
                        ? (int) $model['max_input_tokens']
                        : null,
                    maxOutputTokens: isset($model['max_tokens'])
                        ? (int) $model['max_tokens']
                        : null,
                    modalities: $this->modalities($model),
                    createdAt: isset($model['created_at'])
                        ? new DateTimeImmutable((string) $model['created_at'])
                        : null,
                    raw: $model,
                    outputModalities: ['text'],
                ));
            }

            $hasMore = (bool) $response->json('has_more', false);
            $query['after_id'] = $response->json('last_id');
        } while ($hasMore && filled($query['after_id']));

        return $models->sortBy('id')->values();
    }

    /**
     * @param  array<string, mixed>  $model
     * @return list<string>
     */
    protected function modalities(array $model): array
    {
        $imageInput = $model['capabilities']['image_input']['supported'] ?? null;

        if ($imageInput === false) {
            return ['text'];
        }

        // Anthropic documents image input for all current Claude models. Use
        // that as the fallback for older API responses without capabilities.
        return ['text', 'image'];
    }
}
