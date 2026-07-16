<?php

namespace App\Jobs;

use App\Services\FollowUps\FollowUpEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Daily sweep for companies that have gone quiet (Phase 7).
 *
 * NoActivity fires on the ABSENCE of events, so no event can push it — it has to
 * be pulled on a schedule. Idempotent within a day via the evaluator's dedup key
 * (`quiet:{date}`), so a re-run or an overlapping schedule cannot double-fire.
 */
class EvaluateTimeBasedFollowUps implements ShouldQueue
{
    use Queueable;

    public function handle(FollowUpEvaluator $evaluator): void
    {
        $fired = $evaluator->sweepNoActivity();

        if ($fired > 0) {
            Log::info('Follow-up sweep fired follow-ups for quiet companies.', ['count' => $fired]);
        }
    }
}
