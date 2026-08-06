<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Resolvers;

use LmSomeco\AiModels\Data\AiModel;

/**
 * OpenRouter is OpenAI-compatible for listing but returns far richer metadata
 * (context length, pricing, modalities) and does not require an API key just
 * to enumerate models.
 */
class OpenRouterResolver extends OpenAiCompatibleResolver
{
    public function configured(): bool
    {
        // The /models endpoint is public; a key is only needed for inference.
        return true;
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
            name: $model['name'] ?? (string) $model['id'],
            contextWindow: isset($model['context_length']) ? (int) $model['context_length'] : null,
            maxOutputTokens: isset($model['top_provider']['max_completion_tokens'])
                ? (int) $model['top_provider']['max_completion_tokens']
                : null,
            modalities: $model['architecture']['input_modalities'] ?? [],
            raw: $model,
        );
    }
}
