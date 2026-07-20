<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off period the AI must not book into: a holiday, a conference, a day off.
 *
 * Separate from the recurring rules because "we are closed on 24 December" is not
 * a fact about Tuesdays, and encoding it as one would silently close every
 * Tuesday next year.
 */
class AvailabilityBlock extends Model
{
    use HasFactory, LogsEvents;

    protected $fillable = ['user_id', 'starts_at', 'ends_at', 'reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Blocks that apply to a given rep — their own, plus company-wide ones. */
    public function scopeApplicableTo(Builder $query, ?int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId): void {
            $q->whereNull('user_id');

            if ($userId !== null) {
                $q->orWhere('user_id', $userId);
            }
        });
    }
}
