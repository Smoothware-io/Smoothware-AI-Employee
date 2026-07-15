<?php

namespace App\Providers;

use App\Contracts\CompanyAnalysisLlm;
use App\Contracts\EmbeddingClient;
use App\Contracts\ReceptionistLlm;
use App\Contracts\TelephonyProvider;
use App\Contracts\TranscriptionClient;
use App\Contracts\WebsiteAnalyzer;
use App\Services\Analysis\ClaudeCompanyAnalysisLlm;
use App\Services\Analysis\FakeCompanyAnalysisLlm;
use App\Services\Analysis\FakeWebsiteAnalyzer;
use App\Services\Analysis\HttpWebsiteAnalyzer;
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
        // --- Embeddings (Phase 2) ---
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

        // --- Telephony / receptionist (Phase 3) ---
        $this->app->bind(TelephonyProvider::class, fn ($app): TelephonyProvider => match ($app['config']->get('receptionist.drivers.telephony', 'fake')) {
            'sonetel' => new SonetelProvider,
            default => new FakeTelephonyProvider,
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

        // --- Company analysis (Phase 4) ---
        $this->app->bind(WebsiteAnalyzer::class, fn ($app): WebsiteAnalyzer => match ($app['config']->get('analysis.drivers.website', 'fake')) {
            'http' => new HttpWebsiteAnalyzer((string) $app['config']->get('analysis.pagespeed_key') ?: null),
            default => new FakeWebsiteAnalyzer,
        });

        $this->app->bind(CompanyAnalysisLlm::class, function ($app): CompanyAnalysisLlm {
            if ($app['config']->get('analysis.drivers.llm', 'fake') === 'claude') {
                return new ClaudeCompanyAnalysisLlm(
                    (string) $app['config']->get('services.anthropic.key', ''),
                    (string) $app['config']->get('services.anthropic.model', 'claude-opus-4-8'),
                    (string) $app['config']->get('services.anthropic.effort', 'high'),
                );
            }

            return new FakeCompanyAnalysisLlm;
        });
    }

    public function boot(): void
    {
        //
    }
}
