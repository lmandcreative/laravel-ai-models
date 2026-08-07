<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Enums\Lab;
use LmSomeco\AiModels\Concerns\IsConnector;
use LmSomeco\AiModels\ConnectorManager;
use LmSomeco\AiModels\Contracts\Connector;
use LmSomeco\AiModels\ModelRegistry;

/**
 * A fully custom connector model: its own table, column names, and an integer
 * primary key. Implements every contract method by hand — nothing from the
 * package's AiConnector or IsConnector is reused.
 */
class CustomProviderCredential extends Model implements Connector
{
    protected $table = 'provider_credentials';

    protected $guarded = [];

    public $timestamps = false;

    public static function findConnector(string $id): ?static
    {
        return static::query()->find($id);
    }

    public static function defaultConnector(): ?static
    {
        return static::query()
            ->where('enabled', true)
            ->where('preferred', true)
            ->orderBy('position')
            ->first()
            ?? static::query()
                ->where('enabled', true)
                ->orderBy('position')
                ->first();
    }

    public function getConnectorId(): string
    {
        return (string) $this->getKey();
    }

    public function getProvider(): string
    {
        return (string) $this->getAttribute('driver');
    }

    public function getApiKey(): string
    {
        return (string) $this->getAttribute('secret');
    }

    public function getBaseUrl(): ?string
    {
        $url = $this->getAttribute('endpoint');

        return $url === null ? null : (string) $url;
    }
}

/**
 * A custom model with the standard column layout: the IsConnector trait
 * provides the whole contract, no hand-written methods needed.
 */
class TraitConnector extends Model implements Connector
{
    use IsConnector;

    protected $table = 'custom_ai_connectors';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('provider_credentials', function (Blueprint $table) {
        $table->id();
        $table->string('label');
        $table->string('driver');
        $table->string('secret');
        $table->string('endpoint')->nullable();
        $table->boolean('enabled')->default(true);
        $table->boolean('preferred')->default(false);
        $table->unsignedInteger('position')->default(0);
    });

    Schema::create('custom_ai_connectors', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('provider');
        $table->text('api_key');
        $table->string('base_url')->nullable();
        $table->boolean('is_active')->default(true);
        $table->boolean('is_default')->default(false);
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();
    });
});

function customManager(): ConnectorManager
{
    return new ConnectorManager(
        registry: app(ModelRegistry::class),
        connectorModel: CustomProviderCredential::class,
    );
}

// ---------------------------------------------------------------------------
// resolve() through a fully custom schema
// ---------------------------------------------------------------------------

it('resolves the preferred enabled credential as the default', function () {
    CustomProviderCredential::create([
        'label' => 'Disabled', 'driver' => 'openai', 'secret' => 'sk-off',
        'enabled' => false, 'preferred' => true, 'position' => 0,
    ]);
    CustomProviderCredential::create([
        'label' => 'Fallback', 'driver' => 'openai', 'secret' => 'sk-fallback',
        'enabled' => true, 'preferred' => false, 'position' => 0,
    ]);
    CustomProviderCredential::create([
        'label' => 'Preferred', 'driver' => 'openai', 'secret' => 'sk-preferred',
        'enabled' => true, 'preferred' => true, 'position' => 5,
    ]);

    $connector = customManager()->resolve();

    expect($connector)->toBeInstanceOf(CustomProviderCredential::class)
        ->and($connector->label)->toBe('Preferred');
});

it('falls back to the first enabled credential when none is preferred', function () {
    CustomProviderCredential::create([
        'label' => 'Second', 'driver' => 'openai', 'secret' => 'sk-2',
        'enabled' => true, 'preferred' => false, 'position' => 2,
    ]);
    CustomProviderCredential::create([
        'label' => 'First', 'driver' => 'openai', 'secret' => 'sk-1',
        'enabled' => true, 'preferred' => false, 'position' => 1,
    ]);

    expect(customManager()->resolve()->label)->toBe('First');
});

it('resolves by a stringified integer id', function () {
    $created = CustomProviderCredential::create([
        'label' => 'Named', 'driver' => 'anthropic', 'secret' => 'sk-ant',
    ]);

    $connector = customManager()->resolve((string) $created->getKey());

    expect($connector->getKey())->toBe($created->getKey());
});

it('throws ModelNotFoundException for an unknown id', function () {
    customManager()->resolve('999');
})->throws(ModelNotFoundException::class, CustomProviderCredential::class);

it('throws ModelNotFoundException when no enabled credential exists', function () {
    CustomProviderCredential::create([
        'label' => 'Disabled', 'driver' => 'openai', 'secret' => 'sk-off',
        'enabled' => false,
    ]);

    customManager()->resolve();
})->throws(ModelNotFoundException::class, 'No active AI connector is configured.');

// ---------------------------------------------------------------------------
// configure() maps the custom columns into config('ai.providers')
// ---------------------------------------------------------------------------

it('injects mapped credentials into config at runtime', function () {
    $connector = CustomProviderCredential::create([
        'label' => 'Proxy', 'driver' => 'openai', 'secret' => 'sk-proxy',
        'endpoint' => 'https://proxy.test/v1',
    ]);

    $providerKey = customManager()->configure($connector);

    expect($providerKey)->toBe('db-'.$connector->getKey())
        ->and(config("ai.providers.{$providerKey}.driver"))->toBe('openai')
        ->and(config("ai.providers.{$providerKey}.key"))->toBe('sk-proxy')
        ->and(config("ai.providers.{$providerKey}.url"))->toBe('https://proxy.test/v1');
});

it('omits url from the injected config when the endpoint is null', function () {
    $connector = CustomProviderCredential::create([
        'label' => 'Plain', 'driver' => 'openai', 'secret' => 'sk-plain',
    ]);

    $providerKey = customManager()->configure($connector);

    expect(config("ai.providers.{$providerKey}"))->not->toHaveKey('url');
});

// ---------------------------------------------------------------------------
// models() end-to-end through ModelRegistry::connector()
// ---------------------------------------------------------------------------

it('lists models for a custom connector via models()', function () {
    $connector = CustomProviderCredential::create([
        'label' => 'Proxy', 'driver' => 'openai', 'secret' => 'sk-proxy',
        'endpoint' => 'https://proxy.test/v1',
    ]);

    Http::fake(['proxy.test/*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $models = customManager()->models($connector);

    expect($models)->toHaveCount(1)
        ->and($models->first()->id)->toBe('gpt-4o')
        ->and($models->first()->provider)->toBe(Lab::OpenAI);
});

// ---------------------------------------------------------------------------
// The IsConnector trait on a custom model with standard columns
// ---------------------------------------------------------------------------

it('resolves and configures a trait-based model with zero hand-written methods', function () {
    TraitConnector::create([
        'name' => 'Standard', 'provider' => 'openai', 'api_key' => 'sk-trait',
        'base_url' => 'https://trait.test/v1',
        'is_active' => true, 'is_default' => true, 'sort_order' => 0,
    ]);

    $manager = new ConnectorManager(
        registry: app(ModelRegistry::class),
        connectorModel: TraitConnector::class,
    );

    $connector = $manager->resolve();
    $providerKey = $manager->configure($connector);

    expect($connector)->toBeInstanceOf(TraitConnector::class)
        ->and($connector->name)->toBe('Standard')
        ->and(config("ai.providers.{$providerKey}.driver"))->toBe('openai')
        ->and(config("ai.providers.{$providerKey}.key"))->toBe('sk-trait')
        ->and(config("ai.providers.{$providerKey}.url"))->toBe('https://trait.test/v1');
});

// ---------------------------------------------------------------------------
// Container wiring + config validation
// ---------------------------------------------------------------------------

it('resolves the configured custom model through the container', function () {
    config(['ai-models.connectors.model' => CustomProviderCredential::class]);

    CustomProviderCredential::create([
        'label' => 'Wired', 'driver' => 'openai', 'secret' => 'sk-wired',
    ]);

    expect(app(ConnectorManager::class)->resolve())
        ->toBeInstanceOf(CustomProviderCredential::class);
});

it('rejects a configured class that does not implement the contract', function () {
    config(['ai-models.connectors.model' => stdClass::class]);

    app(ConnectorManager::class);
})->throws(InvalidArgumentException::class, Connector::class);

it('rejects a non-string configured model value', function () {
    config(['ai-models.connectors.model' => 42]);

    app(ConnectorManager::class);
})->throws(InvalidArgumentException::class, 'int');
