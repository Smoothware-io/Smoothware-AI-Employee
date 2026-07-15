<?php

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\PromptRuleSetStatus;
use App\Models\PromptRuleSet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Governs the "exactly one active ruleset" invariant. Activating a version
 * archives whichever version was active before, in one transaction, and records
 * the change to the event log.
 */
class PromptRuleSetService
{
    public function __construct(private EventLogger $events) {}

    /** The ruleset currently governing AI behaviour (null if none activated). */
    public function active(): ?PromptRuleSet
    {
        return PromptRuleSet::active()->latest('version')->first();
    }

    public function nextVersion(): int
    {
        return (int) PromptRuleSet::max('version') + 1;
    }

    public function activate(PromptRuleSet $set, ?User $by = null): PromptRuleSet
    {
        return DB::transaction(function () use ($set, $by) {
            PromptRuleSet::active()
                ->whereKeyNot($set->getKey())
                ->get()
                ->each(fn (PromptRuleSet $prev) => $prev->forceFill([
                    'status' => PromptRuleSetStatus::Archived,
                ])->saveQuietly());

            $set->forceFill([
                'status' => PromptRuleSetStatus::Active,
                'activated_at' => now(),
                'activated_by' => $by?->getKey(),
            ])->saveQuietly();

            $this->events->log(
                action: 'prompt_rule_set.activated',
                entity: $set,
                payload: ['version' => $set->version],
                actorType: $by ? ActorType::User : null,
                actorId: $by?->getKey(),
            );

            return $set;
        });
    }
}
