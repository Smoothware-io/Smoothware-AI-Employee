<?php

namespace App\Models;

use App\Enums\AiActionStatus;
use App\Services\AiActionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An AI-proposed action moving through the human-in-the-loop approval flow.
 * Created and transitioned exclusively via {@see AiActionService}
 * so that every transition is validated and audited — do not mutate `status`
 * directly.
 *
 * @property string $action_type
 * @property AiActionStatus $status
 * @property array $proposed_payload
 * @property float|null $confidence_score
 */
class AiAction extends Model
{
    use SoftDeletes;

    /** Smoothware soft-delete convention. */
    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'action_type',
        'status',
        'proposed_payload',
        'target_type',
        'target_id',
        'confidence_score',
        'source_context_version',
        'model_id',
        'ai_run_id',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AiActionStatus::class,
            'proposed_payload' => 'array',
            'confidence_score' => 'decimal:3',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'draft',
    ];

    // --- Relationships -----------------------------------------------------

    /** The record produced/affected once applied (loose polymorphic ref). */
    public function target(): MorphTo
    {
        return $this->morphTo('target', 'target_type', 'target_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // --- Scopes ------------------------------------------------------------

    /** The review queue: drafts awaiting a human decision, newest first. */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('status', AiActionStatus::Draft)->latest();
    }

    // --- State helpers -----------------------------------------------------

    public function isDraft(): bool
    {
        return $this->status === AiActionStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === AiActionStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === AiActionStatus::Rejected;
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }
}
