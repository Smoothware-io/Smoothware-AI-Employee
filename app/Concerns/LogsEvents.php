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
 * GDPR note: the event log is APPEND-ONLY, so it can never satisfy a
 * right-to-erasure request on its own. We therefore practice **reference
 * logging** — the *values* of personal-data fields are never written here.
 * A model lists such fields in `$auditRedacted`; the trail still records that
 * those fields changed (by name), keeping the audit complete without persisting
 * PII into an immutable store. `$hidden` attributes are always redacted too.
 *
 * @mixin Model
 */
trait LogsEvents
{
    public const REDACTED = '[redacted]';

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
     * Callers passing custom payloads are responsible for not including PII.
     */
    public function recordEvent(string $verb, array $payload = []): void
    {
        app(EventLogger::class)->log(
            action: $this->eventName().'.'.$verb,
            entity: $this,
            payload: $payload,
            companyId: $this->eventTimelineCompanyId(),
        );
    }

    /** Snake-cased model basename used as the event namespace (e.g. "company"). */
    public function eventName(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * The company this model's events anchor to on the timeline. Default reads a
     * `company_id` attribute (covers Contact/Note/Task/Appointment/Call); the
     * Company model overrides to return its own key.
     */
    public function eventTimelineCompanyId(): ?int
    {
        $companyId = $this->getAttribute('company_id');

        return $companyId === null ? null : (int) $companyId;
    }

    /**
     * Attribute names whose VALUES must never be written to the append-only log
     * (personal data, secrets). Override per model:
     *   protected array $auditRedacted = ['email', 'phone', 'first_name'];
     *
     * @return array<int, string>
     */
    public function auditRedacted(): array
    {
        $declared = property_exists($this, 'auditRedacted') ? $this->auditRedacted : [];

        return array_values(array_unique(array_merge($this->getHidden(), $declared)));
    }

    /** Attributes captured on creation, with PII values scrubbed. */
    protected function eventCreatedPayload(): array
    {
        return ['attributes' => $this->scrubForAudit($this->attributesToArray())];
    }

    /** Before/after diff of the changed attributes on update, PII scrubbed. */
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

        return [
            'before' => $this->scrubForAudit($before),
            'after' => $this->scrubForAudit($after),
        ];
    }

    /**
     * Replace the values of redacted (PII/secret) attributes with a marker,
     * preserving the key so the audit still shows *that* the field was involved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function scrubForAudit(array $data): array
    {
        foreach ($this->auditRedacted() as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = self::REDACTED;
            }
        }

        return $data;
    }
}
