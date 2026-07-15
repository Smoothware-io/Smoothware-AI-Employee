<?php

namespace App\Http\Controllers;

use App\Contracts\TelephonyProvider;
use App\Enums\CallStatus;
use App\Enums\RecordSource;
use App\Jobs\ProcessInboundCall;
use App\Models\Call;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives inbound-call webhooks from the telephony provider (Sonetel in prod),
 * creates the factual Call record, and queues the receptionist pipeline. The
 * call itself is created (it happened); the AI's proposed CRM records are drafts
 * requiring human approval (shadow mode).
 */
class InboundCallWebhookController extends Controller
{
    public function __invoke(Request $request, TelephonyProvider $telephony): JsonResponse
    {
        // ⚠️ UNVERIFIED / placeholder auth. Replace with Sonetel's real webhook
        // signature verification before go-live. A configured secret is required
        // to match; unset (dev only) accepts unauthenticated.
        $secret = config('receptionist.webhook_secret');
        if ($secret && ! hash_equals((string) $secret, (string) $request->header('X-Webhook-Secret'))) {
            abort(401);
        }

        $data = $telephony->parseInboundWebhook($request->all());

        $call = Call::create([
            'direction' => $data->direction,
            'status' => CallStatus::Completed,
            'from_number' => $data->fromNumber,
            'to_number' => $data->toNumber,
            'started_at' => $data->startedAt,
            'ended_at' => $data->endedAt,
            'duration_seconds' => $data->durationSeconds,
            'transcript' => $data->transcript,
            'transcript_status' => $data->transcript ? 'done' : 'pending',
            'external_provider' => $data->provider,
            'external_id' => $data->externalId,
            'recording_disk' => $data->recordingDisk,
            'recording_path' => $data->recordingPath,
            'recording_bytes' => $data->recordingBytes,
            'consent_obtained' => $data->consentObtained,
            'consent_method' => $data->consentMethod,
            'disclosed_at' => $data->consentObtained ? now() : null,
            'retention_expires_at' => now()->addDays((int) config('receptionist.recording.retention_days', 90)),
            'source' => RecordSource::System,
        ]);

        ProcessInboundCall::dispatch($call->id);

        return response()->json(['status' => 'accepted', 'call_id' => $call->id], 202);
    }
}
