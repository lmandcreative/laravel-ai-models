<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Data;

use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Enums\ModelRole;

/**
 * A single AI model, normalized across every provider into a common shape.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class AiModel implements Arrayable
{
    /**
     * @param  string  $provider  The provider name the model was listed under: a config/ai.php key (e.g. "openai") or an on-demand name (e.g. "db-5").
     * @param  Lab  $driver  The laravel/ai driver behind that provider.
     * @param  list<string>  $modalities  Input modalities the model accepts (e.g. ["text", "image"]).
     * @param  array<string, mixed>  $raw  The untouched payload returned by the provider.
     * @param  list<string>  $outputModalities  Output modalities the model can generate (e.g. ["text", "image"]).
     * @param  list<ModelRole>  $roles  Roles the SDK assigns this model on its provider (default, cheapest, smartest, ...).
     */
    public function __construct(
        public string $provider,
        public Lab $driver,
        public string $id,
        public ?string $name = null,
        public ?int $contextWindow = null,
        public ?int $maxOutputTokens = null,
        public array $modalities = [],
        public ?DateTimeImmutable $createdAt = null,
        public array $raw = [],
        public array $outputModalities = [],
        public array $roles = [],
    ) {}

    public function accepts(string $modality): bool
    {
        return in_array($modality, $this->modalities, true);
    }

    public function generates(string $modality): bool
    {
        return in_array($modality, $this->outputModalities, true);
    }

    /**
     * Whether the SDK assigns this model the given role on its provider.
     */
    public function is(ModelRole $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * A copy of this model carrying the given roles.
     *
     * @param  list<ModelRole>  $roles
     */
    public function withRoles(array $roles): self
    {
        return new self(
            provider: $this->provider,
            driver: $this->driver,
            id: $this->id,
            name: $this->name,
            contextWindow: $this->contextWindow,
            maxOutputTokens: $this->maxOutputTokens,
            modalities: $this->modalities,
            createdAt: $this->createdAt,
            raw: $this->raw,
            outputModalities: $this->outputModalities,
            roles: $roles,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'driver' => $this->driver->value,
            'id' => $this->id,
            'name' => $this->name,
            'context_window' => $this->contextWindow,
            'max_output_tokens' => $this->maxOutputTokens,
            'modalities' => $this->modalities,
            'output_modalities' => $this->outputModalities,
            'roles' => array_map(static fn (ModelRole $role): string => $role->value, $this->roles),
            'created_at' => $this->createdAt?->format(DATE_ATOM),
        ];
    }
}
