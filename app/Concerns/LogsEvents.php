<?php

namespace App\Concerns;

use App\Services\EventLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Opt-in on any feature model (`use LogsEvents;`) to automatically write to the
 * universal event log on create / update / archive / restore, plus a
 * {@see recordEvent()} helper for domain-specific actions (e.g. a task status
 * transition). This is how Phase 1+ features feed the Company Timeline without
 * each one re-implementing logging.
 *
 * @mixin Model
 */
trait LogsEvents
{
    public static function bootLogsEvents(): void
    {
        static::created(function (Model $model): void {
            /** @var static $model */
            $model->recordEvent('created', $model->eventCreatedPayload());
        });

        static::updated(function (Model $model): void {
            /** @var static $model */
            $changes = $model->eventChangedPayload();

            if ($changes !== []) {
                $model->recordEvent('updated', $changes);
            }
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            // Soft delete = archive in Smoothware's vocabulary.
            static::deleted(fn (Model $model) => $model->recordEvent('archived'));
            static::forceDeleted(fn (Model $model) => $model->recordEvent('deleted'));
            static::restored(fn (Model $model) => $model->recordEvent('restored'));
        } else {
            static::deleted(fn (Model $model) => $model->recordEvent('deleted'));
        }
    }

    /**
     * Record a domain event for this model, namespaced by its event name, e.g.
     * $task->recordEvent('status_changed', [...]) -> action "task.status_changed".
     */
    public function recordEvent(string $verb, array $payload = []): void
    {
        app(EventLogger::class)->log(
            action: $this->eventName().'.'.$verb,
            entity: $this,
            payload: $payload,
        );
    }

    /** Snake-cased model basename used as the event namespace (e.g. "company"). */
    public function eventName(): string
    {
        return Str::snake(class_basename($this));
    }

    /** Attributes captured on creation (hidden attributes are excluded). */
    protected function eventCreatedPayload(): array
    {
        return $this->attributesToArray();
    }

    /** Before/after diff of the changed attributes on update. */
    protected function eventChangedPayload(): array
    {
        $after = $this->getChanges();
        unset($after['updated_at']);

        if ($after === []) {
            return [];
        }

        $before = [];
        foreach (array_keys($after) as $key) {
            $before[$key] = $this->getOriginal($key);
        }

        return ['before' => $before, 'after' => $after];
    }
}
