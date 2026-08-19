<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Resolvers;

use LmSomeco\AiModels\Data\AiModel;

/**
 * Enriches OpenAI's sparse model-list response with documented modalities.
 *
 * OpenAI's GET /models endpoint only exposes identity and ownership metadata,
 * so modalities are derived from stable official model-family names. Unknown
 * model IDs deliberately remain unknown rather than receiving a guessed type.
 */
class OpenAiResolver extends OpenAiCompatibleResolver
{
    /**
     * @param  array<string, mixed>  $model
     */
    protected function mapModel(array $model): ?AiModel
    {
        $mapped = parent::mapModel($model);

        if (! $mapped instanceof AiModel) {
            return null;
        }

        [$modalities, $outputModalities] = $this->modalitiesFor($mapped->id);

        return new AiModel(
            provider: $mapped->provider,
            id: $mapped->id,
            name: $mapped->name,
            contextWindow: $mapped->contextWindow,
            maxOutputTokens: $mapped->maxOutputTokens,
            modalities: $modalities,
            createdAt: $mapped->createdAt,
            raw: $mapped->raw,
            outputModalities: $outputModalities,
        );
    }

    /**
     * @return array{list<string>, list<string>}
     */
    protected function modalitiesFor(string $model): array
    {
        $model = $this->baseModel($model);

        if ($this->matches('/^(gpt-image-|chatgpt-image-|dall-e-)/', $model)) {
            return [['text', 'image'], ['image']];
        }

        if (str_starts_with($model, 'sora-')) {
            return [['text', 'image'], ['video', 'audio']];
        }

        if (str_contains($model, 'transcribe') || str_contains($model, 'realtime-whisper')) {
            return [['text', 'audio'], ['text']];
        }

        if ($model === 'whisper-1') {
            return [['audio'], ['text']];
        }

        if ($this->matches('/^(tts-|gpt-.*-tts(?:-|$))/', $model)) {
            return [['text'], ['audio']];
        }

        if (str_contains($model, 'realtime')) {
            return [['text', 'image', 'audio'], ['text', 'audio']];
        }

        if ($this->matches('/^gpt-(?:4o(?:-mini)?-)?audio(?:-|$)/', $model)) {
            return [['text', 'audio'], ['text', 'audio']];
        }

        if ($this->matches('/^(text-embedding-|text-similarity-|text-search-|code-search-)/', $model)) {
            return [['text'], ['embedding']];
        }

        if (str_starts_with($model, 'omni-moderation')) {
            return [['text', 'image'], ['text']];
        }

        if (str_starts_with($model, 'text-moderation')) {
            return [['text'], ['text']];
        }

        if ($this->matches('/^(?:o1-(?:mini|preview)|o3-mini)(?:-|$)/', $model)) {
            return [['text'], ['text']];
        }

        if ($this->isVisionModel($model)) {
            return [['text', 'image'], ['text']];
        }

        if ($this->isTextModel($model)) {
            return [['text'], ['text']];
        }

        return [[], []];
    }

    protected function baseModel(string $model): string
    {
        if (! str_starts_with($model, 'ft:')) {
            return $model;
        }

        return explode(':', $model, 3)[1] ?? $model;
    }

    protected function isVisionModel(string $model): bool
    {
        return $this->matches(
            '/^(gpt-(?:5(?:\.\d+)?|4\.[15]|4o)(?:-|$)|gpt-4-(?:turbo|vision)|chatgpt-4o|o1(?:-|$)|o3(?:-|$)|o4(?:-|$)|computer-use-)/',
            $model,
        );
    }

    protected function isTextModel(string $model): bool
    {
        return $this->matches(
            '/^(gpt-|chatgpt-|gpt-oss-|codex-|babbage-|davinci-)/',
            $model,
        );
    }

    protected function matches(string $pattern, string $model): bool
    {
        return preg_match($pattern, $model) === 1;
    }
}
