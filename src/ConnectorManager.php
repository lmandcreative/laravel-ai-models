<?php

declare(strict_types=1);

namespace LmSomeco\AiModels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\Provider;
use LmSomeco\AiModels\Contracts\Connector;
use LmSomeco\AiModels\Data\AiModel;
use LmSomeco\AiModels\Models\AiConnector;

/**
 * Resolves the active connector record and bridges it to the AI SDK.
 *
 * Typical usage:
 *
 *   $connector = $manager->resolve();            // default active connector
 *   $provider  = $manager->provider($connector); // on-demand laravel/ai provider
 *
 *   // Return it from an agent's provider() method, or pass its name:
 *   (new SalesCoach)->prompt('...', provider: $provider->name());
 *
 *   // List the connector's models:
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
        protected AiManager $ai,
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
     * Build an on-demand laravel/ai provider, named "db-{id}", for the given
     * connector.
     *
     * Nothing is written to config('ai.providers'). The provider serializes
     * with its configuration encrypted, so it is safe to hand to queued
     * agents; laravel/ai flushes on-demand providers between queue jobs and
     * Octane operations, so build it where the work runs (e.g. an agent's
     * provider() method).
     */
    public function provider(Connector $connector): Provider
    {
        return $this->ai->build(array_filter([
            'name' => 'db-'.$connector->getConnectorId(),
            'driver' => $connector->getDriver()->value,
            'key' => $connector->getApiKey(),
            'url' => $connector->getBaseUrl(),
        ], filled(...)));
    }

    /**
     * List the models available for the given connector.
     *
     * Cached under the connector's "db-{id}" provider name; pass
     * $fresh = true to bypass the cache, or AiModels::refresh('db-{id}') to
     * drop it.
     *
     * @return Collection<int, AiModel>
     */
    public function models(Connector $connector, bool $fresh = false): Collection
    {
        return $this->registry->provider($this->provider($connector), $fresh);
    }
}
