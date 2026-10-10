<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Contracts;

use Laravel\Ai\Enums\Lab;

/**
 * A source of AI provider credentials that ConnectorManager can resolve and
 * turn into an on-demand laravel/ai provider via Ai::build().
 *
 * Implement this on any Eloquent model to use your own table and columns, or
 * use LmSomeco\AiModels\Concerns\IsConnector for the standard ai_connectors
 * column layout.
 */
interface Connector
{
    /**
     * Find a connector by the identifier passed to ConnectorManager::resolve().
     * Return null when it does not exist (the manager throws).
     */
    public static function findConnector(string $id): ?static;

    /**
     * The connector to use when none is specified: your "active default",
     * with whatever fallback ordering makes sense. Return null when no
     * connector is available (the manager throws).
     */
    public static function defaultConnector(): ?static;

    /**
     * Stable string identity, used in the "db-{id}" provider name (which is
     * also the model cache key). Stringify non-string primary keys.
     */
    public function getConnectorId(): string;

    /**
     * The underlying laravel/ai driver (e.g. Lab::OpenAI).
     */
    public function getDriver(): Lab;

    /**
     * The decrypted API key.
     */
    public function getApiKey(): string;

    /**
     * Base-URL override, or null to use the provider's default.
     */
    public function getBaseUrl(): ?string;
}
