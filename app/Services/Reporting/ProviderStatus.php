<?php

namespace App\Services\Reporting;

use App\Providers\AppServiceProvider;

/**
 * Which external providers are still offline fakes (Phase 8).
 *
 * Exists so the dashboard can say so out loud. Every AI number on that page is
 * computed from runs produced by whatever adapter is bound, and while those are
 * fakes the numbers describe our stubs, not the world. A screenshot of a green
 * "2% fallback rate" taken before Voyage and real telephony are wired would be
 * read as a KPI by someone who wasn't in these conversations.
 *
 * This reads the SAME config keys that {@see AppServiceProvider}
 * binds on, rather than a hardcoded flag — so the warning disappears by itself
 * the moment a real provider is configured, instead of rotting into a stale
 * banner nobody remembers to remove.
 */
class ProviderStatus
{
    /**
     * config key => [label, the value that means "real"]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PROVIDERS = [
        'receptionist.drivers.llm' => ['Receptionist LLM', 'claude'],
        'receptionist.drivers.telephony' => ['Telephony', 'sonetel'],
        'analysis.drivers.llm' => ['Analysis LLM', 'claude'],
        'analysis.drivers.website' => ['Website scan', 'http'],
        'services.embeddings.driver' => ['Embeddings', 'voyage'],
    ];

    /**
     * Providers still running on an offline fake.
     *
     * @return array<int, string>
     */
    public function fakes(): array
    {
        $fakes = [];

        foreach (self::PROVIDERS as $key => [$label, $realDriver]) {
            if (config($key, 'fake') !== $realDriver) {
                $fakes[] = $label;
            }
        }

        return $fakes;
    }

    /** @return array<int, string> */
    public function live(): array
    {
        $live = [];

        foreach (self::PROVIDERS as $key => [$label, $realDriver]) {
            if (config($key, 'fake') === $realDriver) {
                $live[] = $label;
            }
        }

        return $live;
    }

    /** True while ANY provider is faked — i.e. while AI metrics are not real signal. */
    public function hasFakes(): bool
    {
        return $this->fakes() !== [];
    }

    public function allFake(): bool
    {
        return $this->live() === [];
    }

    /**
     * Transcription has no real implementation yet at all (Phase 3 shipped only
     * FakeTranscriptionClient), so it is not in PROVIDERS — there is no config
     * value that could make it true. Called out separately to avoid implying a
     * switch exists.
     */
    public function transcriptionIsStubbed(): bool
    {
        return true;
    }
}
