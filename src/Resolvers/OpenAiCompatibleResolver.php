<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Resolvers;

use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use LmSomeco\AiModels\Data\AiModel;

/**
 * Handles any provider that exposes an OpenAI-style `GET /models` endpoint
 * returning `{ "data": [ { "id": "..." }, ... ] }` with Bearer auth.
 *
 * Covers Groq, Mistral, DeepSeek, xAI and laravel/ai's generic
 * "openai-compatible" driver out of the box — they differ only by base URL,
 * which comes from config. OpenAI uses a small subclass to enrich the sparse
 * response with its documented model-family modalities.
 */
class OpenAiCompatibleResolver extends Resolver
{
    protected function fetch(): Collection
    {
        $response = $this->request()->get($this->modelsPath())->throw();
        $data = $response->json('data', []);

        return collect(is_array($data) ? $data : [])
            ->map(fn (array $model): ?AiModel => $this->mapModel($model))
            ->filter()
            ->sortBy('id')
            ->values();
    }

    protected function modelsPath(): string
    {
        return '/models';
    }

    protected function request(): PendingRequest
    {
        $request = parent::request();

        if (filled($this->apiKey())) {
            $request->withToken($this->apiKey());
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $model
     */
    protected function mapModel(array $model): ?AiModel
    {
        if (empty($model['id'])) {
            return null;
        }

        return $this->newModel(
            id: (string) $model['id'],
            name: (string) $model['id'],
            createdAt: isset($model['created'])
                ? (new DateTimeImmutable)->setTimestamp((int) $model['created'])
                : null,
            raw: $model,
        );
    }
}
