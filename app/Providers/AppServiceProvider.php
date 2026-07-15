<?php

namespace App\Providers;

use App\Contracts\EmbeddingClient;
use App\Services\Embeddings\FakeEmbeddingClient;
use App\Services\Embeddings\VoyageEmbeddingClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Resolve the embeddings provider from config. Defaults to the offline
        // fake (dev/tests/CI); set EMBEDDINGS_DRIVER=voyage + VOYAGE_API_KEY for
        // production. Anthropic's API has no embeddings endpoint, hence this.
        $this->app->bind(EmbeddingClient::class, function ($app): EmbeddingClient {
            $config = (array) $app['config']->get('services.embeddings', []);

            return match ($config['driver'] ?? 'fake') {
                'voyage' => new VoyageEmbeddingClient(
                    (string) ($config['voyage']['key'] ?? ''),
                    (string) ($config['voyage']['model'] ?? 'voyage-3'),
                    (int) ($config['voyage']['dimensions'] ?? 1024),
                ),
                default => new FakeEmbeddingClient,
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
