<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Data;

use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Enums\Lab;

/**
 * A single AI model, normalized across every provider into a common shape.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class AiModel implements Arrayable
{
    /**
     * @param  list<string>  $modalities  Input modalities the model accepts (e.g. ["text", "image"]).
     * @param  array<string, mixed>  $raw  The untouched payload returned by the provider.
     */
    public function __construct(
        public Lab $provider,
        public string $id,
        public ?string $name = null,
        public ?int $contextWindow = null,
        public ?int $maxOutputTokens = null,
        public array $modalities = [],
        public ?DateTimeImmutable $createdAt = null,
        public array $raw = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'id' => $this->id,
            'name' => $this->name,
            'context_window' => $this->contextWindow,
            'max_output_tokens' => $this->maxOutputTokens,
            'modalities' => $this->modalities,
            'created_at' => $this->createdAt?->format(DATE_ATOM),
        ];
    }
}
