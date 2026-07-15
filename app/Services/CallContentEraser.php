<?php

namespace App\Services;

use App\Models\Call;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Destroys a call's personal content — recording object, transcript, summary,
 * phone numbers — while keeping the call metadata (direction, duration,
 * timestamps) for reporting integrity. Used by the Phase 3 retention-purge job
 * and by on-demand GDPR erasure. Idempotent.
 */
class CallContentEraser
{
    public function __construct(private EventLogger $events) {}

    public function erase(Call $call, ?User $by = null, string $reason = 'manual'): Call
    {
        if ($call->isContentErased()) {
            return $call;
        }

        // Remove the recording bytes from object storage (never stored in the DB).
        if ($call->recording_disk !== null && $call->recording_path !== null) {
            Storage::disk($call->recording_disk)->delete($call->recording_path);
        }

        $call->forceFill([
            'from_number' => null,
            'to_number' => null,
            'transcript' => null,
            'summary' => null,
            'recording_disk' => null,
            'recording_path' => null,
            'recording_bytes' => null,
            'transcript_status' => null,
            'content_erased_at' => now(),
            'erased_by' => $by?->getKey(),
        ])->saveQuietly(); // one purpose-built event instead of a generic update

        $this->events->log(
            action: 'call.content_erased',
            entity: $call,
            payload: ['reason' => $reason, 'erased_by' => $by?->getKey()],
            companyId: $call->company_id,
        );

        return $call;
    }
}
