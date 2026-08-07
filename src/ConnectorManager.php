<?php

declare(strict_types=1);

namespace LmSomeco\AiModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use LmSomeco\AiModels\Contracts\Connector;
use LmSomeco\AiModels\Data\AiModel;
use LmSomeco\AiModels\Models\AiConnector;

/**
 * Resolves the active connector record and bridges it to the AI SDK.
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
 * To substitute your own Eloquent model, set ai-models.connectors.model to any
 * Eloquent model implementing Contracts\Connector — extend AiConnector, use
 * Concerns\IsConnector, or implement the contract's methods yourself.
 */
class ConnectorManager
{
    /**
     * @param  class-string<Model&Connector>  $connectorModel
     */
    public function __construct(
        protected ModelRegistry $registry,
        protected string $connectorModel = AiConnector::class,
    ) {}

    /**
     * Resolve the connector to use.
     *
     * When $id is provided the connector with that identifier is returned (or
     * ModelNotFoundException is thrown if it does not exist). Otherwise
     * selection is delegated to Connector::defaultConnector() — for the
     * shipped AiConnector: the active default, falling back to the first
     * active connector by sort order. Throws ModelNotFoundException if none
     * is found.
     */
    public function resolve(?string $id = null): Connector
    {
        $model = $this->connectorModel;

        if ($id !== null) {
            $connector = $model::findConnector($id);

            if (! $connector instanceof Connector) {
                throw (new ModelNotFoundException)->setModel($model, [$id]);
            }

            return $connector;
        }

        $connector = $model::defaultConnector();

        if (! $connector instanceof Connector) {
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
    public function configure(Connector $connector): string
    {
        $providerKey = 'db-'.$connector->getConnectorId();

        config([
            "ai.providers.{$providerKey}" => array_filter([
                'driver' => $connector->getProvider(),
                'key' => $connector->getApiKey(),
                'url' => $connector->getBaseUrl(),
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
    public function models(Connector $connector, bool $fresh = false): Collection
    {
        return $this->registry->connector($connector, $fresh);
    }
}
