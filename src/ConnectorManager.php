<?php

declare(strict_types=1);

namespace LmSomeco\AiModels;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use LmSomeco\AiModels\Data\AiModel;
use LmSomeco\AiModels\Models\AiConnector;

/**
 * Resolves the active AiConnector record and bridges it to the AI SDK.
 *
 * Typical usage:
 *
 *   $connector   = $manager->resolve();           // default active connector
 *   $providerKey = $manager->configure($connector); // injects into config at runtime
 *   Laravel\Ai\Facades\Ai::provider($providerKey)->ask(...);
 *
 *   // Or use models() to list available models directly:
 *   $models = $manager->models($connector);
 *
 * To substitute your own Eloquent model, set ai-models.connectors.model in
 * your config (or bind it in the container). The class must extend AiConnector.
 */
class ConnectorManager
{
    /**
     * @param  class-string<AiConnector>  $connectorModel
     */
    public function __construct(
        protected ModelRegistry $registry,
        protected string $connectorModel = AiConnector::class,
    ) {}

    /**
     * Resolve the AiConnector to use.
     *
     * When $id is provided the connector with that UUID is returned (or
     * ModelNotFoundException is thrown if it does not exist). Otherwise the
     * default active connector is returned, falling back to the first active
     * connector by sort order. Throws ModelNotFoundException if none is found.
     */
    public function resolve(?string $id = null): AiConnector
    {
        $model = $this->connectorModel;

        if ($id !== null) {
            /** @var AiConnector */
            return $model::findOrFail($id);
        }

        $connector = $model::active()->default()->ordered()->first()
            ?? $model::active()->ordered()->first();

        if (! $connector instanceof AiConnector) {
            throw new ModelNotFoundException('No active AI connector is configured.');
        }

        return $connector;
    }

    /**
     * Register a runtime provider entry in config('ai.providers') for the given
     * connector and return the provider name to pass to the SDK.
     *
     * The Laravel AI SDK resolves providers from config('ai.providers.*'), so
     * the decrypted key is injected at runtime rather than stored in .env.
     * The config mutation is process-local and lasts for the current request.
     */
    public function configure(AiConnector $connector): string
    {
        $providerKey = 'db-'.$connector->id;

        config([
            "ai.providers.{$providerKey}" => array_filter([
                'driver' => $connector->provider,
                'key' => $connector->api_key,
                'url' => $connector->base_url,
            ]),
        ]);

        return $providerKey;
    }

    /**
     * List the models available for the given connector.
     *
     * Delegates to ModelRegistry::connector() which uses a stable per-connector
     * cache key. Pass $fresh = true to bypass the cache.
     *
     * @return Collection<int, AiModel>
     */
    public function models(AiConnector $connector, bool $fresh = false): Collection
    {
        return $this->registry->connector($connector, $fresh);
    }
}
