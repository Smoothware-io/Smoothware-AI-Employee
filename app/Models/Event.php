<?php

namespace App\Models;

use App\Enums\ActorType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One row in the universal audit log. APPEND ONLY — see the migration. The
 * model enforces that contract: attempts to update or delete an event throw.
 *
 * @property string $entity_type
 * @property int|null $entity_id
 * @property int|null $company_id
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property array|null $payload
 */
class Event extends Model
{
    /** Append-only: we set created_at ourselves and never touch updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'company_id',
        'actor_type',
        'actor_id',
        'action',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Events are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Events are append-only and cannot be deleted.');
        });
    }

    /**
     * The record this event is about. Works when entity_type holds a model
     * class (the common case). System-level events use entity_type = 'system'.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }

    /** The company this event is anchored to, for the timeline feed. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The acting user, when actor_type is `user`. Null for ai_agent/system. */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** A company's activity feed: everything anchored to it, newest first. */
    public function scopeForCompanyTimeline(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId)->latest('created_at')->latest('id');
    }
}
