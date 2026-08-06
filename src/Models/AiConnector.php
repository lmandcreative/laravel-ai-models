<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A database-driven AI provider configuration.
 *
 * Applications can extend this class to add relationships or override the table
 * name, then bind the subclass in the service container so ConnectorManager
 * resolves it automatically:
 *
 *   $this->app->bind(\LmSomeco\AiModels\Models\AiConnector::class, MyConnector::class);
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
class AiConnector extends Model
{
    use HasUuids;

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
