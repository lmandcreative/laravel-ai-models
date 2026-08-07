<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Default Contracts\Connector implementation for Eloquent models using the
 * standard ai_connectors column layout: provider, api_key, base_url,
 * is_active, is_default and sort_order (plus created_at for tie-breaking).
 *
 * Models with different column names should implement the contract's methods
 * themselves instead of using this trait.
 *
 * @phpstan-require-extends Model
 */
trait IsConnector
{
    public static function findConnector(string $id): ?static
    {
        return static::query()->find($id);
    }

    public static function defaultConnector(): ?static
    {
        return static::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->first()
            ?? static::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->first();
    }

    public function getConnectorId(): string
    {
        return (string) $this->getKey();
    }

    public function getProvider(): string
    {
        return (string) $this->getAttribute('provider');
    }

    public function getApiKey(): string
    {
        return (string) $this->getAttribute('api_key');
    }

    public function getBaseUrl(): ?string
    {
        $url = $this->getAttribute('base_url');

        return $url === null ? null : (string) $url;
    }
}
