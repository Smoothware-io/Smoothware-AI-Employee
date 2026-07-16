<?php

namespace App\Enums;

use App\Models\Event;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * What makes a follow-up rule fire (Phase 7).
 *
 * Triggers are matched against the Phase-0 append-only event log rather than
 * against bespoke hooks: every model already emits `{model}.{verb}` on
 * create/update/archive, so the log IS the trigger stream. {@see forEvent()} is
 * the single place that maps a logged event to a trigger.
 *
 * NoActivity is the odd one out — it fires on the ABSENCE of events, so it
 * cannot be event-driven and is swept on a schedule instead ({@see isTimeBased()}).
 */
enum FollowUpTrigger: string implements HasDescription, HasLabel
{
    case CallLogged = 'call_logged';
    case CompanyImported = 'company_imported';
    case AnalysisGenerated = 'analysis_generated';
    case AppointmentScheduled = 'appointment_scheduled';
    case TaskCompleted = 'task_completed';
    case CompanyStatusChanged = 'company_status_changed';
    case NoActivity = 'no_activity';

    public function getLabel(): string
    {
        return match ($this) {
            self::CallLogged => 'A call is logged',
            self::CompanyImported => 'A company is imported',
            self::AnalysisGenerated => 'An AI analysis is generated',
            self::AppointmentScheduled => 'An appointment is scheduled',
            self::TaskCompleted => 'A task is completed',
            self::CompanyStatusChanged => 'A company changes status',
            self::NoActivity => 'A company goes quiet',
        };
    }

    public function getDescription(): ?string
    {
        return match ($this) {
            self::CallLogged => 'Fires when a call is recorded against a company.',
            self::CompanyImported => 'Fires for companies created by a CSV import (not manual or AI ones).',
            self::AnalysisGenerated => 'Fires when a new AI company analysis lands.',
            self::AppointmentScheduled => 'Fires when an appointment is booked.',
            self::TaskCompleted => 'Fires when a task moves to completed — useful for chaining next steps.',
            self::CompanyStatusChanged => 'Fires when a company moves between statuses (e.g. lead → qualified).',
            self::NoActivity => 'Fires when a company has had no timeline activity for the configured window.',
        };
    }

    /** Time-based triggers fire on absence, so a scheduled sweep finds them. */
    public function isTimeBased(): bool
    {
        return $this === self::NoActivity;
    }

    /**
     * Map a logged event to the trigger it satisfies, or null if none does.
     *
     * Matching is on the event `action` string (stable, and what LogsEvents
     * actually writes) plus, where the action alone is too coarse, a payload
     * check — e.g. `company.created` only counts as CompanyImported when the
     * company's source is `import`.
     */
    public static function forEvent(Event $event): ?self
    {
        $payload = $event->payload ?? [];

        return match ($event->action) {
            'call.created' => self::CallLogged,
            'company_ai_analysis.created' => self::AnalysisGenerated,
            'appointment.created' => self::AppointmentScheduled,

            'company.created' => ($payload['attributes']['source'] ?? null) === RecordSource::Import->value
                ? self::CompanyImported
                : null,

            'task.status_changed' => ($payload['to'] ?? null) === TaskStatus::Completed->value
                ? self::TaskCompleted
                : null,

            'company.updated' => array_key_exists('status', $payload['after'] ?? [])
                ? self::CompanyStatusChanged
                : null,

            default => null,
        };
    }

    /** Event-driven triggers, i.e. everything a logged event could satisfy. */
    public static function eventDriven(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t): bool => ! $t->isTimeBased()));
    }
}
