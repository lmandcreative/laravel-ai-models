<?php

declare(strict_types=1);

namespace LmSomeco\AiModels\Resolvers;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use LmSomeco\AiModels\Contracts\ProviderResolver;
use LmSomeco\AiModels\Data\AiModel;

/**
 * Base class for resolvers that list models over HTTP.
 *
 * Credentials and base URL are read from the laravel/ai Provider instance,
 * falling back to the resolver defaults in config/ai-models.php. Transport
 * failures are rethrown as the SDK's failover exceptions
 * (ProviderConnectionException, RateLimitedException,
 * ProviderOverloadedException, InsufficientCreditsException), so callers can
 * handle them exactly like a failed prompt.
 */
abstract class Resolver implements ProviderResolver
{
    use HandlesFailoverErrors;

    /**
     * @param  array<string, mixed>  $config  Resolver defaults from config/ai-models.php (url, timeout, ...).
     */
    public function __construct(
        protected HttpFactory $http,
        protected Provider $provider,
        protected array $config = [],
    ) {}

    /**
     * Fetch and normalize the provider's models.
     *
     * @return Collection<int, AiModel>
     */
    abstract protected function fetch(): Collection;

    public function provider(): Provider
    {
        return $this->provider;
    }

    public function configured(): bool
    {
        if (blank($this->baseUrl())) {
            return false;
        }

        return ! ($this->config['requires_key'] ?? true) || filled($this->apiKey());
    }

    final public function models(): Collection
    {
        return $this->withErrorHandling($this->name(), fn (): Collection => $this->fetch());
    }

    /**
     * The provider name models are listed under (config key or on-demand name).
     */
    protected function name(): string
    {
        return $this->provider->name();
    }

    protected function driver(): Lab
    {
        return Lab::from($this->provider->driver());
    }

    protected function apiKey(): ?string
    {
        // The SDK's base Provider reads $config['key'] unguarded, which throws
        // for an on-demand provider built without one.
        $key = rescue(fn (): mixed => $this->provider->providerCredentials()['key'] ?? null, null, false);

        return is_string($key) && $key !== '' ? $key : null;
    }

    protected function baseUrl(): string
    {
        $url = $this->provider->additionalConfiguration()['url'] ?? null;

        return rtrim((string) (filled($url) ? $url : ($this->config['url'] ?? '')), '/');
    }

    protected function timeout(): int
    {
        return (int) ($this->config['timeout'] ?? 15);
    }

    protected function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->timeout($this->timeout());
    }

    /**
     * Build an AiModel for this resolver's provider.
     *
     * @param  list<string>  $modalities
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $outputModalities
     */
    protected function newModel(
        string $id,
        ?string $name = null,
        ?int $contextWindow = null,
        ?int $maxOutputTokens = null,
        array $modalities = [],
        ?DateTimeImmutable $createdAt = null,
        array $raw = [],
        array $outputModalities = [],
    ): AiModel {
        return new AiModel(
            provider: $this->name(),
            driver: $this->driver(),
            id: $id,
            name: $name,
            contextWindow: $contextWindow,
            maxOutputTokens: $maxOutputTokens,
            modalities: $modalities,
            createdAt: $createdAt,
            raw: $raw,
            outputModalities: $outputModalities,
        );
    }

    /**
     * Status codes that mean the provider is transiently unavailable. Labs
     * with their own codes (Anthropic's 529, Groq's 498) set
     * "overloaded_status_codes" on their resolver entry.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        /** @var list<int> */
        return $this->config['overloaded_status_codes'] ?? [502, 503, 504, 520, 522, 524];
    }
}
