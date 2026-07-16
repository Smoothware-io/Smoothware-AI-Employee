<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use App\Enums\SuppressionSource;
use App\Enums\SuppressionType;
use App\Services\SuppressionList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "do not contact me again" instruction. Created via {@see SuppressionList}.
 *
 * NO SoftDeletes on purpose — see the migration. This row must survive the
 * erasure of the contact it protects, or an erasure request becomes the reason
 * we call someone who objected. Corrections are {@see release()}d, never deleted.
 *
 * @property SuppressionType $type
 * @property SuppressionSource $source
 */
class Suppression extends Model
{
    use HasFactory, LogsEvents;

    protected $fillable = [
        'type',
        'value_normalized',
        'value_raw',
        'source',
        'reason',
        'suppressed_at',
        'created_by',
        'released_at',
        'released_reason',
        'released_by',
    ];

    /**
     * The suppressed address IS personal data, so its value never enters the
     * append-only event log — the log records that a suppression happened, not
     * whose. Reference logging, same as everywhere else.
     *
     * @var array<int, string>
     */
    protected array $auditRedacted = ['value_normalized', 'value_raw'];

    protected function casts(): array
    {
        return [
            'type' => SuppressionType::class,
            'source' => SuppressionSource::class,
            'suppressed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** Live suppressions — the only ones that block contact. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /**
     * Undo a suppression — because it was a mistake, or the person re-consented.
     * Requires a reason: letting the system call someone again is consequential,
     * and "who decided that, and why?" must be answerable later.
     */
    public function release(string $reason, ?int $userId = null): self
    {
        $this->forceFill([
            'released_at' => now(),
            'released_reason' => $reason,
            'released_by' => $userId,
        ])->save();

        $this->recordEvent('released', ['reason' => $reason]);

        return $this;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
