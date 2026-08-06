<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Resolvers;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Contracts\ProviderResolver;
use LmSomeco\AiModels\Data\AiModel;

/**
 * Handles any provider that exposes an OpenAI-style `GET /models` endpoint
 * returning `{ "data": [ { "id": "..." }, ... ] }` with Bearer auth.
 *
 * Covers OpenAI, Groq, Mistral, DeepSeek and xAI out of the box — they differ
 * only by base URL, which comes from config. No subclass needed for those.
 */
class OpenAiCompatibleResolver implements ProviderResolver
{
    /**
     * @param  array<string, mixed>  $config  Merged config/ai.php provider config + resolver defaults.
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
        return filled($this->apiKey());
    }

    public function models(): Collection
    {
        $response = $this->request()->get($this->modelsPath())->throw();
        $data = $response->json('data', []);

        return collect(is_array($data) ? $data : [])
            ->map(fn (array $model): ?AiModel => $this->mapModel($model))
            ->filter()
            ->sortBy('id')
            ->values();
    }

    protected function apiKey(): ?string
    {
        return $this->config['key'] ?? null;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config['url'] ?? ''), '/');
    }

    protected function modelsPath(): string
    {
        return '/models';
    }

    protected function request(): PendingRequest
    {
        $request = $this->http
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 15));

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

        return new AiModel(
            provider: $this->provider(),
            id: (string) $model['id'],
            name: (string) $model['id'],
            createdAt: isset($model['created'])
                ? (new DateTimeImmutable)->setTimestamp((int) $model['created'])
                : null,
            raw: $model,
        );
    }
}
