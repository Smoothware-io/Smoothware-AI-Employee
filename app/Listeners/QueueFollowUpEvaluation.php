<?php

namespace App\Listeners;

use App\Enums\FollowUpTrigger;
use App\Events\EventLogged;
use App\Jobs\EvaluateFollowUpsForEvent;

/**
 * Bridges the universal event log to follow-up automation (Phase 7).
 *
 * Runs synchronously and does no database work: it only asks whether the logged
 * event maps to a trigger at all, and queues the real evaluation if so. Every
 * write to the event log passes through here, so a DB query — let alone a queued
 * job — per event would be a tax on the whole application.
 */
class QueueFollowUpEvaluation
{
    public function handle(EventLogged $logged): void
    {
        // Follow-ups are company-anchored; an event with no company can't fire one.
        if ($logged->event->company_id === null) {
            return;
        }

        if (FollowUpTrigger::forEvent($logged->event) === null) {
            return;
        }

        EvaluateFollowUpsForEvent::dispatch($logged->event->getKey());
    }
}
