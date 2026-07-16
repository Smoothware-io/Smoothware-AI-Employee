<?php

namespace App\Models;

use App\Concerns\HasProvenance;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpTrigger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One follow-up DECISION (Phase 7) — the ledger entry, not the work item. The
 * work item is an ordinary Phase-1 {@see Task}; this row records that the
 * automation decided to create it, why, and from which rule as it read at the
 * time.
 *
 * Skipped/failed rows are kept deliberately: "why didn't the automation fire?"
 * is as important a question as "why did it?".
 *
 * @property FollowUpStatus $status
 * @property FollowUpTrigger $trigger
 * @property array|null $rule_snapshot
 */
class FollowUp extends Model
{
    use HasFactory, HasProvenance;

    protected $fillable = [
        'follow_up_rule_id',
        'company_id',
        'contact_id',
        'trigger',
        'trigger_event_id',
        'rule_snapshot',
        'reason',
        'task_id',
        'status',
        'due_at',
        'source',
        'ai_action_id',
        'confidence_score',
        'source_context_version',
        'model_id',
        'ai_run_id',
        'dedup_key',
    ];

    protected $attributes = [
        'status' => 'applied',
        'source' => 'system',
    ];

    protected function casts(): array
    {
        return [
            'status' => FollowUpStatus::class,
            'trigger' => FollowUpTrigger::class,
            'rule_snapshot' => 'array',
            'due_at' => 'datetime',
            'confidence_score' => 'float',
        ];
    }

    /**
     * The idempotency key. Deterministic for a given (rule, company, cause), so a
     * re-run collides on the UNIQUE index instead of creating a duplicate task.
     *
     * `$cause` distinguishes what made it fire: the triggering event id for
     * event-driven rules, or the sweep date for time-based ones (a quiet company
     * should be able to fire again tomorrow, but not twice today).
     */
    public static function dedupKey(?int $ruleId, int $companyId, string $cause): string
    {
        return sprintf('rule:%s|company:%d|cause:%s', $ruleId ?? 'ai', $companyId, $cause);
    }

    // --- Relationships -----------------------------------------------------

    public function rule(): BelongsTo
    {
        return $this->belongsTo(FollowUpRule::class, 'follow_up_rule_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** The exact logged event that fired this — the link back to the timeline. */
    public function triggerEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'trigger_event_id');
    }
}
