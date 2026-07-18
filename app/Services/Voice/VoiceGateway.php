<?php

namespace App\Services\Voice;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Hands an accepted call off to go-voice so the gateway opens the control
 * WebSocket and starts executing the AI's tool calls (ARCHITECTURE §15.6).
 *
 * Deliberately soft-failing: the hand-off must never break a call that OpenAI is
 * already carrying. If the gateway is unreachable, the call still happens — the
 * AI just talks without hands, and there is no transcript. A dropped tool is bad;
 * a dropped conversation is worse.
 */
class VoiceGateway
{
    public function configured(): bool
    {
        return filled(config('voice.gateway_url')) && filled(config('voice.service_token'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function handOff(string $callId, array $context = []): void
    {
        if (! $this->configured()) {
            return;
        }

        try {
            $response = Http::withToken((string) config('voice.service_token'))
                ->asJson()
                ->timeout(5)
                ->post(rtrim((string) config('voice.gateway_url'), '/').'/calls', array_merge([
                    'call_id' => $callId,
                ], $context));

            if ($response->failed()) {
                Log::warning('voice gateway hand-off failed', [
                    'call_id' => $callId,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('voice gateway unreachable', [
                'call_id' => $callId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
