<?php

namespace App\Services\Telephony;

use App\Contracts\TelephonyProvider;
use App\Enums\CallDirection;
use App\Support\Receptionist\InboundCallData;
use Illuminate\Support\Carbon;

/**
 * Sonetel telephony adapter.
 *
 * ⚠️ UNVERIFIED: every field mapping below is an ASSUMPTION about Sonetel's
 * webhook payload made WITHOUT their API docs. Each `// UNVERIFIED` marks a shape
 * to confirm against the real docs before going live. Sonetel may also not
 * provide transcripts — in which case `transcript` stays null and the pipeline
 * falls back to the TranscriptionClient. Do not enable TELEPHONY_DRIVER=sonetel
 * until these are verified.
 */
class SonetelProvider implements TelephonyProvider
{
    public function parseInboundWebhook(array $payload): InboundCallData
    {
        return new InboundCallData(
            provider: 'sonetel',
            externalId: $payload['call_id'] ?? null,                              // UNVERIFIED
            direction: CallDirection::Inbound,
            fromNumber: $payload['from_number'] ?? $payload['caller'] ?? null,    // UNVERIFIED
            toNumber: $payload['to_number'] ?? $payload['callee'] ?? null,        // UNVERIFIED
            startedAt: isset($payload['started_at']) ? Carbon::parse($payload['started_at']) : null, // UNVERIFIED
            endedAt: isset($payload['ended_at']) ? Carbon::parse($payload['ended_at']) : null,       // UNVERIFIED
            durationSeconds: isset($payload['duration']) ? (int) $payload['duration'] : null,        // UNVERIFIED
            transcript: $payload['transcript'] ?? null,                           // UNVERIFIED (may be absent)
            recordingPath: $payload['recording_url'] ?? null,                     // UNVERIFIED
            consentObtained: null,                                                // set by our consent flow
            consentMethod: null,
        );
    }

    public function name(): string
    {
        return 'sonetel';
    }
}
