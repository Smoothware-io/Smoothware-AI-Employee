<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use App\Enums\PromptRuleSetStatus;
use App\Services\PromptRuleSetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned set of behavioural rules that govern AI conversations. Exactly one
 * set is active; that version is recorded on every AI call. Transition via
 * {@see PromptRuleSetService} — do not set `status` directly.
 *
 * @property PromptRuleSetStatus $status
 */
class PromptRuleSet extends Model
{
    use HasFactory, LogsEvents;

    protected $fillable = [
        'version',
        'status',
        'notes',
        'activated_at',
        'activated_by',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => PromptRuleSetStatus::class,
            'activated_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PromptRuleSetStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === PromptRuleSetStatus::Active;
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PromptRule::class)->orderBy('sort_order');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
