<?php

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Models\Call;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * go-voice posts the finished transcript on hang-up. It lands in the SAME
 * encrypted, retention-tracked column the PHP observer wrote to — this replaces
 * ObserveRealtimeCall's storage, so the GDPR pipeline (CallContentEraser,
 * PurgeExpiredCallContent) keeps working unchanged.
 */
class TranscriptController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'call_id' => ['required', 'string'],
            'transcript' => ['required', 'string'],
        ]);

        $call = Call::query()
            ->where('external_id', $data['call_id'])
            ->latest('id')
            ->first();

        if ($call === null) {
            // Not an error the gateway can act on — the call simply was not one we
            // recorded (a probe, a race). Acknowledge and move on.
            return response()->json(['stored' => false]);
        }

        $call->forceFill([
            'transcript' => $data['transcript'],
            'transcript_status' => 'done',
            'retention_expires_at' => $call->retention_expires_at
                ?? now()->addDays((int) config('receptionist.calls.retention_days', 90)),
        ])->saveQuietly();

        return response()->json(['stored' => true]);
    }
}
