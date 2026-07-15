<?php

namespace App\Models;

use App\Concerns\HasProvenance;
use App\Concerns\LogsEvents;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Exceptions\InvalidTaskTransition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A unit of sales work with a real status state machine (see {@see TaskStatus}).
 * Status is changed ONLY through the transition methods below — they validate
 * the move, keep `completed_at` correct, and emit a single `task.status_changed`
 * event. Never assign `$task->status` directly.
 *
 * @property TaskType $type
 * @property TaskStatus $status
 */
class Task extends Model
{
    use HasFactory, HasProvenance, LogsEvents, SoftDeletes;

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'company_id',
        'type',
        'title',
        'description',
        'status',
        'status_reason',
        'assigned_to',
        'due_at',
        'completed_at',
        'source',
        'ai_action_id',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // --- State machine -----------------------------------------------------

    /**
     * Move the task to a new status, validating the transition. Idempotent when
     * the target equals the current status. Throws {@see InvalidTaskTransition}
     * for a move the machine does not allow.
     */
    public function transitionTo(TaskStatus $to, ?string $reason = null): self
    {
        $from = $this->status;

        if ($from === $to) {
            return $this; // no-op — safe to double-click in the UI
        }

        if (! $from->canTransitionTo($to)) {
            throw InvalidTaskTransition::between($this, $from, $to);
        }

        $this->status = $to;
        $this->status_reason = $reason;
        $this->completed_at = match ($to) {
            TaskStatus::Completed => now(),
            TaskStatus::Open => null,      // reopened
            default => $this->completed_at,
        };

        // saveQuietly so the generic "task.updated" auto-log does not fire — a
        // status change gets one purpose-built event instead.
        $this->saveQuietly();

        $this->recordEvent('status_changed', [
            'from' => $from->value,
            'to' => $to->value,
            'reason' => $reason,
        ]);

        return $this;
    }

    public function start(): self
    {
        return $this->transitionTo(TaskStatus::InProgress);
    }

    public function block(?string $reason = null): self
    {
        return $this->transitionTo(TaskStatus::Blocked, $reason);
    }

    public function unblock(): self
    {
        return $this->transitionTo(TaskStatus::InProgress);
    }

    public function complete(): self
    {
        return $this->transitionTo(TaskStatus::Completed);
    }

    public function cancel(?string $reason = null): self
    {
        return $this->transitionTo(TaskStatus::Cancelled, $reason);
    }

    public function reopen(): self
    {
        return $this->transitionTo(TaskStatus::Open);
    }

    // --- Scopes ------------------------------------------------------------

    /** Not completed or cancelled. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->active()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_to', $userId);
    }

    // --- Relationships -----------------------------------------------------

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
