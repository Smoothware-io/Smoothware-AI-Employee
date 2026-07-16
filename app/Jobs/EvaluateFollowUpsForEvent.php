<?php

namespace App\Jobs;

use App\Listeners\QueueFollowUpEvaluation;
use App\Models\Event;
use App\Services\FollowUps\FollowUpEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Evaluates follow-up rules against one logged event (Phase 7). Queued from
 * {@see QueueFollowUpEvaluation} so that logging an event never
 * waits on automation.
 *
 * Safe to retry: the evaluator's dedup_key makes a second run a no-op.
 */
class EvaluateFollowUpsForEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $eventId) {}

    public function handle(FollowUpEvaluator $evaluator): void
    {
        $event = Event::find($this->eventId);

        if ($event === null) {
            return;
        }

        $evaluator->forEvent($event);
    }
}
