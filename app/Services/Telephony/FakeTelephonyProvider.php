<?php

namespace App\Services\Telephony;

use App\Contracts\TelephonyProvider;
use App\Enums\CallDirection;
use App\Support\Receptionist\InboundCallData;
use Illuminate\Support\Str;

/**
 * Offline telephony provider for dev/tests. Accepts a simple payload
 * (from, to, transcript, duration) and simulates an inbound call — including a
 * ready transcript — so the whole pipeline runs with no vendor account.
 */
class FakeTelephonyProvider implements TelephonyProvider
{
    public function parseInboundWebhook(array $payload): InboundCallData
    {
        return new InboundCallData(
            provider: 'fake',
            externalId: $payload['id'] ?? 'fake_'.Str::random(10),
            direction: CallDirection::Inbound,
            fromNumber: $payload['from'] ?? '+31600000000',
            toNumber: $payload['to'] ?? '+31201234567',
            startedAt: now()->subMinutes(5),
            endedAt: now(),
            durationSeconds: (int) ($payload['duration'] ?? 120),
            transcript: $payload['transcript'] ?? null,
            consentObtained: true,
            consentMethod: 'ivr_disclosure',
        );
    }

    public function name(): string
    {
        return 'fake';
    }
}
