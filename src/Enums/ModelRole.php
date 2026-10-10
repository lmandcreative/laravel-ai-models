<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Enums;

use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;

/**
 * A role the Laravel AI SDK assigns to a model on a given provider: its
 * default text model, its cheapest and smartest text models, and the default
 * model for each other capability the provider supports.
 *
 * Roles come from the SDK provider instance, so they honour any
 * "models.*" overrides in that provider's config/ai.php entry.
 */
enum ModelRole: string
{
    case Text = 'text';
    case CheapestText = 'text.cheapest';
    case SmartestText = 'text.smartest';
    case Image = 'image';
    case Embeddings = 'embeddings';
    case Audio = 'audio';
    case Transcription = 'transcription';
    case Reranking = 'reranking';
    case Classification = 'classification';

    /**
     * The capability contract a provider must implement to have this role.
     *
     * @return class-string<Provider>
     */
    public function contract(): string
    {
        return match ($this) {
            self::Text, self::CheapestText, self::SmartestText => TextProvider::class,
            self::Image => ImageProvider::class,
            self::Embeddings => EmbeddingProvider::class,
            self::Audio => AudioProvider::class,
            self::Transcription => TranscriptionProvider::class,
            self::Reranking => RerankingProvider::class,
            self::Classification => ClassificationProvider::class,
        };
    }

    /**
     * The provider method that names the model holding this role.
     */
    public function method(): string
    {
        return match ($this) {
            self::Text => 'defaultTextModel',
            self::CheapestText => 'cheapestTextModel',
            self::SmartestText => 'smartestTextModel',
            self::Image => 'defaultImageModel',
            self::Embeddings => 'defaultEmbeddingsModel',
            self::Audio => 'defaultAudioModel',
            self::Transcription => 'defaultTranscriptionModel',
            self::Reranking => 'defaultRerankingModel',
            self::Classification => 'defaultClassificationModel',
        };
    }

    /**
     * The model ID holding this role on the given provider, or null when the
     * provider lacks the capability or has no model configured for it (e.g.
     * an openai-compatible provider without "models.text.default").
     */
    public function modelFor(Provider $provider): ?string
    {
        if (! $provider instanceof ($this->contract())) {
            return null;
        }

        $model = rescue(fn (): mixed => $provider->{$this->method()}(), null, false);

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Every role the provider assigns, grouped by model ID.
     *
     * @return array<string, list<self>>
     */
    public static function assignments(Provider $provider): array
    {
        $assignments = [];

        foreach (self::cases() as $role) {
            $model = $role->modelFor($provider);

            if ($model !== null) {
                $assignments[$model][] = $role;
            }
        }

        return $assignments;
    }
}
