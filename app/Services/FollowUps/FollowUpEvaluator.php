<?php

namespace App\Services\FollowUps;

use App\Enums\AnalysisPriority;
use App\Enums\AssigneeStrategy;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpTrigger;
use App\Enums\RecordSource;
use App\Models\Company;
use App\Models\Event;
use App\Models\FollowUp;
use App\Models\FollowUpRule;
use App\Models\Task;
use Illuminate\Database\QueryException;

/**
 * Decides whether a follow-up rule fires, and creates the resulting task
 * (Phase 7).
 *
 * Rule-created tasks are applied immediately and tagged `source = system` — a
 * human wrote the rule, so a human already decided. They deliberately do NOT
 * pass through the AI approval queue; see {@see FollowUpRule}.
 *
 * Every decision is written to the `follow_ups` ledger. Idempotency rests on the
 * UNIQUE `dedup_key`, not on this class remembering what it did.
 */
class FollowUpEvaluator
{
    /** Evaluate every active rule whose trigger this logged event satisfies. */
    public function forEvent(Event $event): void
    {
        $trigger = FollowUpTrigger::forEvent($event);

        if ($trigger === null || $event->company_id === null) {
            return;
        }

        $company = Company::find($event->company_id);

        if ($company === null) {
            return; // archived or hard-deleted between logging and evaluation
        }

        FollowUpRule::active()
            ->forTrigger($trigger)
            ->get()
            ->each(fn (FollowUpRule $rule) => $this->fire(
                rule: $rule,
                company: $company,
                cause: "event:{$event->getKey()}",
                event: $event,
            ));
    }

    /**
     * Sweep for companies that have gone quiet (the NoActivity trigger fires on
     * the ABSENCE of events, so nothing can push it — it must be pulled).
     *
     * "Quiet" is measured against the Phase-1 timeline anchor (`events.company_id`),
     * which is why this needs no separate last_activity_at column to drift.
     */
    public function sweepNoActivity(): int
    {
        $rules = FollowUpRule::active()->forTrigger(FollowUpTrigger::NoActivity)->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $cutoff = now()->subDays((int) config('followups.no_activity_days', 14));
        // Date, not timestamp: a quiet company may fire again tomorrow, never twice today.
        $cause = 'quiet:'.now()->toDateString();
        $fired = 0;

        Company::query()
            ->whereDoesntHave('timelineEvents', fn ($q) => $q->where('created_at', '>=', $cutoff))
            ->cursor()
            ->each(function (Company $company) use ($rules, $cause, &$fired) {
                foreach ($rules as $rule) {
                    if ($this->fire($rule, $company, $cause) !== null) {
                        $fired++;
                    }
                }
            });

        return $fired;
    }

    private function fire(FollowUpRule $rule, Company $company, string $cause, ?Event $event = null): ?FollowUp
    {
        $dedupKey = FollowUp::dedupKey($rule->getKey(), (int) $company->getKey(), $cause);

        // Cheap pre-check; the UNIQUE index below is what actually guarantees it.
        if (FollowUp::where('dedup_key', $dedupKey)->exists()) {
            return null;
        }

        // Not applicable is not a decision — no ledger row, or a daily sweep over
        // every company x rule would write millions of "didn't match" records.
        if (! $this->matches($rule, $company)) {
            return null;
        }

        $ledger = [
            'follow_up_rule_id' => $rule->getKey(),
            'company_id' => $company->getKey(),
            'trigger' => $rule->trigger,
            'trigger_event_id' => $event?->getKey(),
            'rule_snapshot' => $rule->toSnapshot(),
            'source' => RecordSource::System,
            'dedup_key' => $dedupKey,
            'due_at' => now()->addMinutes($rule->delay_minutes),
        ];

        // Being suppressed by the cap IS a decision, so it gets a row — that is
        // how "why didn't I get my follow-up?" stays answerable.
        if ($this->capReached($company)) {
            return $this->write($ledger + [
                'status' => FollowUpStatus::Skipped,
                'reason' => sprintf(
                    'Daily cap of %d follow-ups for this company was already reached.',
                    (int) config('followups.max_per_company_per_day', 5),
                ),
            ]);
        }

        $task = Task::create([
            'company_id' => $company->getKey(),
            'type' => $rule->task_type,
            'title' => $this->render($rule->task_title, $company),
            'description' => $this->render($rule->task_description, $company),
            'due_at' => now()->addMinutes($rule->delay_minutes),
            'assigned_to' => $this->resolveAssignee($rule, $company),
            'source' => RecordSource::System,
        ]);

        return $this->write($ledger + [
            'task_id' => $task->getKey(),
            'status' => FollowUpStatus::Applied,
            'reason' => sprintf('Rule "%s" fired on %s.', $rule->name, $rule->trigger->getLabel()),
        ]);
    }

    /**
     * The UNIQUE dedup_key is the real guard: two workers can race past the
     * pre-check above, and exactly one may win. Losing that race is success, not
     * an error — the follow-up already exists.
     */
    private function write(array $attributes): ?FollowUp
    {
        try {
            return FollowUp::create($attributes);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'dedup_key') || $e->getCode() === '23000' || $e->getCode() === '23505') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Conditions are an AND of the keys present; an empty/absent condition set
     * matches everything.
     *
     * Supported: `company_status` (any-of), `campaign_id`, `min_ai_priority`.
     */
    private function matches(FollowUpRule $rule, Company $company): bool
    {
        $conditions = $rule->conditions ?? [];

        if (($statuses = $conditions['company_status'] ?? null) && ! in_array($company->status->value, (array) $statuses, true)) {
            return false;
        }

        if (($campaignId = $conditions['campaign_id'] ?? null) && (int) $company->campaign_id !== (int) $campaignId) {
            return false;
        }

        if ($minPriority = $conditions['min_ai_priority'] ?? null) {
            $priority = $company->latestAiAnalysis?->inferred_priority;
            $floor = $minPriority instanceof AnalysisPriority ? $minPriority : AnalysisPriority::tryFrom((string) $minPriority);

            if ($priority === null || $floor === null || $priority->rank() < $floor->rank()) {
                return false;
            }
        }

        return true;
    }

    private function capReached(Company $company): bool
    {
        $cap = (int) config('followups.max_per_company_per_day', 5);

        return FollowUp::where('company_id', $company->getKey())
            ->where('status', FollowUpStatus::Applied->value)
            ->where('created_at', '>=', now()->startOfDay())
            ->count() >= $cap;
    }

    /**
     * Resolved at FIRE time, not author time: a rule written months ago should
     * route to whoever owns the company today.
     */
    private function resolveAssignee(FollowUpRule $rule, Company $company): ?int
    {
        return match ($rule->assignee_strategy) {
            AssigneeStrategy::CompanyOwner => $company->owner_id,
            AssigneeStrategy::RuleCreator => $rule->created_by,
            AssigneeStrategy::SpecificUser => $rule->assignee_id,
            AssigneeStrategy::Unassigned => null,
        };
    }

    /** Minimal placeholder rendering — no PII, only company-level fields. */
    private function render(?string $template, Company $company): ?string
    {
        if ($template === null) {
            return null;
        }

        return strtr($template, [
            '{company.name}' => (string) $company->name,
            '{company.domain}' => (string) $company->domain,
            '{company.industry}' => (string) $company->industry,
            '{company.status}' => $company->status->getLabel(),
        ]);
    }
}
