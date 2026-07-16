<?php

namespace App\Listeners;

use App\Enums\FollowUpTrigger;
use App\Events\EventLogged;
use App\Jobs\EvaluateFollowUpsForEvent;
use App\Models\FollowUpRule;

/**
 * Bridges the universal event log to follow-up automation (Phase 7).
 *
 * EVERY write to the event log passes through here, so this stays as close to
 * free as possible and refuses to queue work that would find nothing to do. Two
 * cheap gates before dispatching:
 *
 *   1. does the event map to a trigger at all? (pure match, no I/O)
 *   2. does any ACTIVE RULE exist for that trigger? (one cached lookup)
 *
 * Gate 2 exists because gate 1 alone still queued a job per call and per imported
 * company on an install with no rules configured — each waking a worker only to
 * discover there was nothing to evaluate. Measured on real Postgres: 7 no-op jobs
 * queued against 2 rules.
 */
class QueueFollowUpEvaluation
{
    public function handle(EventLogged $logged): void
    {
        // Follow-ups are company-anchored; an event with no company can't fire one.
        if ($logged->event->company_id === null) {
            return;
        }

        $trigger = FollowUpTrigger::forEvent($logged->event);

        if ($trigger === null) {
            return;
        }

        // No rule wants this trigger — don't pay a worker to find that out.
        if (! FollowUpRule::hasActiveRuleFor($trigger)) {
            return;
        }

        EvaluateFollowUpsForEvent::dispatch($logged->event->getKey());
    }
}
