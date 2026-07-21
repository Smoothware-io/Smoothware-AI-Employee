<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A list of companies to work through, and the rules for working through them.
 *
 * The pace, the limits and the retry policy live on the CAMPAIGN rather than in
 * config, because a client will want them different from ours and re-tuning them
 * must never need a deploy.
 *
 * @property CampaignStatus $status
 */
class Campaign extends Model
{
    use HasFactory, LogsEvents, SoftDeletes;

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'name',
        'description',
        'status',
        'calls_per_hour',
        'max_call_minutes',
        'max_attempts',
        'retry_after_hours',
        'respect_working_hours',
        'objective',
        'started_at',
        'completed_at',
        'last_dialed_at',
        'created_by',
    ];

    protected $attributes = [
        // Draft, never running. A campaign that starts dialling because somebody
        // pressed "create" is the worst possible default in this system.
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'calls_per_hour' => 'integer',
            'max_call_minutes' => 'integer',
            'max_attempts' => 'integer',
            'retry_after_hours' => 'integer',
            'respect_working_hours' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_dialed_at' => 'datetime',
        ];
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', CampaignStatus::Running->value);
    }

    /** Seconds between calls, derived from the per-hour pace. */
    public function secondsBetweenCalls(): int
    {
        return (int) floor(3600 / max(1, $this->calls_per_hour));
    }

    /** Has enough time passed since the last call to place the next one? */
    public function isDueToDial(): bool
    {
        if ($this->last_dialed_at === null) {
            return true;
        }

        return $this->last_dialed_at->copy()->addSeconds($this->secondsBetweenCalls())->isPast();
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
