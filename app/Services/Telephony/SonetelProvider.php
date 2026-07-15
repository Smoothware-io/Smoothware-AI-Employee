<?php

namespace App\Services\Telephony;

use App\Contracts\TelephonyProvider;
use App\Enums\CallDirection;
use App\Support\Receptionist\InboundCallData;
use Illuminate\Support\Carbon;

/**
 * Sonetel telephony adapter — POST-CALL model (confirmed against Sonetel's docs,
 * 2026-07).
 *
 * Sonetel's public API has NO real-time media-streaming / WebSocket audio API
 * (unlike Twilio Media Streams / Telnyx). Its Telephony API covers number
 * management, call forwarding, Cloud IVR / voice apps (SIP), a Call Recording
 * API (list/download/delete recordings AFTER the call), and usage records. So
 * the receptionist runs post-call: a call is handled live by Sonetel's IVR /
 * voicemail / a human, Sonetel records it, then we pull the recording, transcribe
 * it, and draft records for review. "Live AI on the call" is NOT buildable on
 * Sonetel — it needs a SIP media server (FreeSWITCH/Asterisk/Pipecat) or a
 * second vendor (Twilio/Telnyx). Tracked as a separate decision (ARCHITECTURE §9).
 *
 * This method parses a Sonetel "call-completed" callback if one is configured;
 * otherwise the activation step adds a scheduled job that polls the Call
 * Recording API for new recordings and feeds them through the same pipeline.
 *
 * ⚠️ UNVERIFIED: the field mappings below are ASSUMPTIONS pending hands-on access
 * to Sonetel's recording/callback payloads. Each `// UNVERIFIED` marks a shape to
 * confirm. Do not enable TELEPHONY_DRIVER=sonetel until verified.
 */
class SonetelProvider implements TelephonyProvider
{
    public function parseInboundWebhook(array $payload): InboundCallData
    {
        return new InboundCallData(
            provider: 'sonetel',
            externalId: $payload['call_id'] ?? $payload['recording_id'] ?? null,   // UNVERIFIED
            direction: CallDirection::Inbound,
            fromNumber: $payload['from_number'] ?? $payload['caller'] ?? null,      // UNVERIFIED
            toNumber: $payload['to_number'] ?? $payload['callee'] ?? null,          // UNVERIFIED
            startedAt: isset($payload['started_at']) ? Carbon::parse($payload['started_at']) : null, // UNVERIFIED
            endedAt: isset($payload['ended_at']) ? Carbon::parse($payload['ended_at']) : null,       // UNVERIFIED
            durationSeconds: isset($payload['duration']) ? (int) $payload['duration'] : null,        // UNVERIFIED
            transcript: $payload['transcript'] ?? null,           // UNVERIFIED (Sonetel may not transcribe — else use STT)
            recordingPath: $payload['recording_url'] ?? null,     // UNVERIFIED (Call Recording API download URL)
            consentObtained: null,                                // set by our IVR consent/disclosure flow
            consentMethod: null,
        );
    }

    public function name(): string
    {
        return 'sonetel';
    }
}
