<?php

namespace App\Providers;

use App\Contracts\EmbeddingClient;
use App\Contracts\ReceptionistLlm;
use App\Contracts\TelephonyProvider;
use App\Contracts\TranscriptionClient;
use App\Services\Embeddings\FakeEmbeddingClient;
use App\Services\Embeddings\VoyageEmbeddingClient;
use App\Services\Receptionist\ClaudeReceptionistLlm;
use App\Services\Receptionist\FakeReceptionistLlm;
use App\Services\Telephony\FakeTelephonyProvider;
use App\Services\Telephony\FakeTranscriptionClient;
use App\Services\Telephony\SonetelProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Embeddings (Phase 2). Defaults to the offline fake; VOYAGE in prod.
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

        // Telephony (Phase 3). Everything external is a fake by default so the
        // pipeline runs with no vendor account.
        $this->app->bind(TelephonyProvider::class, function ($app): TelephonyProvider {
            return match ($app['config']->get('receptionist.drivers.telephony', 'fake')) {
                'sonetel' => new SonetelProvider,
                default => new FakeTelephonyProvider,
            };
        });

        $this->app->bind(TranscriptionClient::class, fn (): TranscriptionClient => new FakeTranscriptionClient);

        $this->app->bind(ReceptionistLlm::class, function ($app): ReceptionistLlm {
            if ($app['config']->get('receptionist.drivers.llm', 'fake') === 'claude') {
                return new ClaudeReceptionistLlm(
                    (string) $app['config']->get('services.anthropic.key', ''),
                    (string) $app['config']->get('services.anthropic.model', 'claude-opus-4-8'),
                    (string) $app['config']->get('services.anthropic.effort', 'high'),
                );
            }

            return new FakeReceptionistLlm;
        });
    }

    public function boot(): void
    {
        //
    }
}
