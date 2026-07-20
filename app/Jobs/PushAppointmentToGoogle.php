<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\GoogleCalendarAccount;
use App\Services\Google\GoogleCalendarClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Put a booked meeting into the connected Google calendars.
 *
 * QUEUED, not inline. The AI books while a caller is still on the phone; a slow
 * Google would be dead air the caller hears. The meeting is already safe in the
 * CRM by the time this runs, so a delay here costs nothing and a failure costs
 * only the convenience copy.
 */
class PushAppointmentToGoogle implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $appointmentId) {}

    public function handle(GoogleCalendarClient $client): void
    {
        $appointment = Appointment::with('company')->find($this->appointmentId);

        if ($appointment === null || $appointment->google_event_id !== null) {
            // Already pushed, or deleted before we got here. Both are fine, and
            // re-pushing would put a duplicate in somebody's real calendar.
            return;
        }

        $accounts = GoogleCalendarAccount::query()->where('push_appointments', true)->get();

        foreach ($accounts as $account) {
            $event = $client->createEvent(
                account: $account,
                title: $appointment->title,
                startsAt: $appointment->starts_at,
                endsAt: $appointment->ends_at ?? $appointment->starts_at->copy()->addMinutes(30),
                description: $this->description($appointment),
            );

            if ($event !== null && $appointment->google_event_id === null) {
                // Only the first is recorded. With several connected calendars
                // this is the one we can later delete; the rest are copies. A
                // known limit of the single-column design, and an honest one — a
                // per-calendar table can come when a second rep actually connects.
                $appointment->forceFill([
                    'google_event_id' => $event['id'],
                    'google_html_link' => $event['html_link'],
                ])->saveQuietly();
            }
        }
    }

    private function description(Appointment $appointment): string
    {
        $lines = ['Booked by the Smoothware AI assistant.'];

        if ($appointment->company !== null) {
            $lines[] = 'Company: '.$appointment->company->name;
        }

        return implode("\n", $lines);
    }
}
