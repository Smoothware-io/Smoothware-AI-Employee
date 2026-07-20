<?php

namespace App\Services\Voice;

use App\Enums\CallStatus;
use App\Models\Call;
use Illuminate\Support\Carbon;

/**
 * Closes a call: gives it an end time, a duration, and an outcome.
 *
 * Nothing did this before, so every call ever placed sat at "In progress"
 * forever — the list read as though a dozen conversations were still live, and
 * `duration_seconds` stayed null, which silently broke every metric built on it.
 * A call with no outcome is not a cosmetic problem: reporting cannot count it,
 * and follow-up rules cannot fire on it.
 *
 * Idempotent. go-voice can retry a transcript post, and the stale sweeper may
 * race it — neither may overwrite an end time that is already recorded.
 */
class CallFinalizer
{
    public function close(Call $call, CallStatus $status = CallStatus::Completed): Call
    {
        if ($call->ended_at !== null) {
            return $call;
        }

        $endedAt = Carbon::now();

        $call->forceFill([
            'status' => $status,
            'ended_at' => $endedAt,
            // Clamp at zero: a clock skew between the gateway host and this one
            // must never produce a negative duration in a report.
            'duration_seconds' => $call->started_at
                ? max(0, $call->started_at->diffInSeconds($endedAt, absolute: false))
                : null,
        ])->saveQuietly();

        return $call;
    }
}
