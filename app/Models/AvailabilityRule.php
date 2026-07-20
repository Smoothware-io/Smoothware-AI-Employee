<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recurring window in which the AI may book: "Mondays, 09:00–17:00".
 *
 * `user_id` null means company-wide (today's case). Set means that rep's own
 * hours — see the migration for why the column exists before the feature does.
 */
class AvailabilityRule extends Model
{
    use HasFactory, LogsEvents;

    protected $fillable = ['user_id', 'weekday', 'starts_at', 'ends_at', 'is_active'];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Rules that apply to a given rep — their own, plus the company-wide ones.
     */
    public function scopeApplicableTo(Builder $query, ?int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId): void {
            $q->whereNull('user_id');

            if ($userId !== null) {
                $q->orWhere('user_id', $userId);
            }
        });
    }

    public static function weekdayName(int $weekday): string
    {
        return [
            1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
            5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
        ][$weekday] ?? 'Unknown';
    }
}
