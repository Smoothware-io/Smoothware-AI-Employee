<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use App\Enums\AssigneeStrategy;
use App\Enums\FollowUpTrigger;
use App\Listeners\QueueFollowUpEvaluation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * A standing follow-up instruction written by a human (Phase 7).
 *
 * Because a person authored it, the tasks it creates are applied immediately and
 * tagged `source = system` — they are NOT routed through the AI approval queue.
 * Sending human-authored automation through that queue would train reviewers to
 * rubber-stamp it, which is precisely what devalues the queue for the AI
 * proposals that do need scrutiny.
 *
 * @property FollowUpTrigger $trigger
 * @property AssigneeStrategy $assignee_strategy
 * @property array|null $conditions
 */
class FollowUpRule extends Model
{
    use HasFactory, LogsEvents, SoftDeletes;

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'name',
        'description',
        'trigger',
        'conditions',
        'delay_minutes',
        'task_type',
        'task_title',
        'task_description',
        'assignee_strategy',
        'assignee_id',
        'is_active',
        'created_by',
    ];

    protected $attributes = [
        'task_type' => 'follow_up',
        'assignee_strategy' => 'company_owner',
        'is_active' => true,
        'delay_minutes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'trigger' => FollowUpTrigger::class,
            'assignee_strategy' => AssigneeStrategy::class,
            'conditions' => 'array',
            'is_active' => 'boolean',
            'delay_minutes' => 'integer',
        ];
    }

    /**
     * Which triggers currently have at least one active rule. Cached because
     * EVERY write to the event log asks this question — see
     * {@see QueueFollowUpEvaluation}.
     */
    public const ACTIVE_TRIGGERS_CACHE_KEY = 'followups.active_triggers';

    protected static function booted(): void
    {
        // Any change to a rule can change the answer: created, renamed, activated,
        // deactivated, archived, restored.
        static::saved(fn () => static::forgetActiveTriggers());
        static::deleted(fn () => static::forgetActiveTriggers());
        static::restored(fn () => static::forgetActiveTriggers());
    }

    /**
     * Trigger values with at least one active rule.
     *
     * Note the invalidation boundary: this is flushed by MODEL events, so a mass
     * `FollowUpRule::query()->update(...)` would bypass it and leave the cache
     * stale. Change rules through the model (the panel does).
     *
     * @return array<int, string>
     */
    public static function activeTriggers(): array
    {
        return Cache::rememberForever(self::ACTIVE_TRIGGERS_CACHE_KEY, fn (): array => static::query()
            ->active()
            ->distinct()
            ->pluck('trigger')
            ->map(fn ($trigger): string => $trigger instanceof FollowUpTrigger ? $trigger->value : (string) $trigger)
            ->all());
    }

    public static function hasActiveRuleFor(FollowUpTrigger $trigger): bool
    {
        return in_array($trigger->value, static::activeTriggers(), true);
    }

    public static function forgetActiveTriggers(): void
    {
        Cache::forget(self::ACTIVE_TRIGGERS_CACHE_KEY);
    }

    /**
     * Frozen into `follow_ups.rule_snapshot` at fire time. Editing this rule
     * afterwards must not rewrite what already happened.
     */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'trigger' => $this->trigger->value,
            'conditions' => $this->conditions,
            'delay_minutes' => $this->delay_minutes,
            'task_type' => $this->task_type,
            'task_title' => $this->task_title,
            'task_description' => $this->task_description,
            'assignee_strategy' => $this->assignee_strategy->value,
            'assignee_id' => $this->assignee_id,
        ];
    }

    // --- Scopes ------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForTrigger(Builder $query, FollowUpTrigger $trigger): Builder
    {
        return $query->where('trigger', $trigger->value);
    }

    // --- Relationships -----------------------------------------------------

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
