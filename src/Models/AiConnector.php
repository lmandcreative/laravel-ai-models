<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LmSomeco\AiModels\Concerns\IsConnector;
use LmSomeco\AiModels\Contracts\Connector;

/**
 * A database-driven AI provider configuration.
 *
 * Applications can substitute their own model by pointing the
 * ai-models.connectors.model config key at any Eloquent model implementing
 * Contracts\Connector: extend this class, use Concerns\IsConnector on a model
 * whose columns match the standard layout, or implement the contract's
 * methods yourself for a custom schema.
 *
 * @property string $id
 * @property string $name
 * @property string $provider A Lab enum value (e.g. "openai", "anthropic").
 * @property string $api_key Stored encrypted.
 * @property string|null $model Optional default model ID.
 * @property string|null $base_url Override the provider's default API base URL.
 * @property array<string, mixed>|null $settings Extra driver-specific settings.
 * @property bool $is_active
 * @property bool $is_default
 * @property int $sort_order
 */
class AiConnector extends Model implements Connector
{
    use HasUuids, IsConnector;

    protected $fillable = [
        'name',
        'provider',
        'api_key',
        'model',
        'base_url',
        'settings',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'settings' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @param  Builder<AiConnector>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<AiConnector>  $query
     */
    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    /**
     * @param  Builder<AiConnector>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('created_at');
    }
}
