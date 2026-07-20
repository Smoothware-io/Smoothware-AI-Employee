<?php

namespace App\Observers;

use App\Enums\AppointmentStatus;
use App\Jobs\PushAppointmentToGoogle;
use App\Jobs\RemoveAppointmentFromGoogle;
use App\Models\Appointment;

/**
 * Keeps Google in step with the CRM.
 *
 * The ownership rule this enforces (ARCHITECTURE §17):
 *
 *   The CRM owns the RECORD.  Google owns the PERSON'S TIME.
 *
 * So the CRM pushes every appointment it creates into Google and withdraws it
 * when it is cancelled; Google is read only for busy time and never creates a
 * CRM record. One direction of authority per concern, which is what stops the
 * two systems arguing.
 *
 * Without the withdrawal half, a cancelled meeting stays in Google forever and
 * freeBusy keeps reporting a slot as taken — an AI that slowly runs out of
 * availability for no visible reason.
 */
class AppointmentObserver
{
    /**
     * A manually created appointment goes to Google too.
     *
     * The AI's own bookings dispatch this from VoiceToolRegistry as well; the
     * job is idempotent on `google_event_id`, so the double dispatch produces
     * one event, not two.
     */
    public function created(Appointment $appointment): void
    {
        if ($appointment->status === AppointmentStatus::Scheduled) {
            PushAppointmentToGoogle::dispatch($appointment->getKey());
        }
    }

    public function updated(Appointment $appointment): void
    {
        if (! $appointment->wasChanged('status')) {
            return;
        }

        $stillOn = $appointment->status === AppointmentStatus::Scheduled;

        if (! $stillOn && filled($appointment->google_event_id)) {
            RemoveAppointmentFromGoogle::dispatch($appointment->google_event_id);

            // Cleared so a later re-schedule pushes a fresh event rather than
            // trying to update one that no longer exists.
            $appointment->forceFill([
                'google_event_id' => null,
                'google_html_link' => null,
            ])->saveQuietly();
        }
    }

    public function deleted(Appointment $appointment): void
    {
        if (filled($appointment->google_event_id)) {
            RemoveAppointmentFromGoogle::dispatch($appointment->google_event_id);
        }
    }
}
