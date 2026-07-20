<?php

namespace App\Jobs;

use App\Models\GoogleCalendarAccount;
use App\Services\Google\GoogleCalendarClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Take a cancelled meeting back out of Google.
 *
 * This is the half that stops the two systems diverging. Without it, cancelling
 * in the CRM leaves the event in Google forever — where freeBusy keeps reporting
 * it, so the AI goes on refusing a slot that has been free for weeks. The
 * calendars do not "conflict" loudly; they rot quietly, and the only symptom is
 * an AI that mysteriously has no availability.
 *
 * Takes the id as a plain string, not the model: by the time this runs the
 * appointment may be gone, and the event still has to be removed.
 */
class RemoveAppointmentFromGoogle implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $googleEventId) {}

    public function handle(GoogleCalendarClient $client): void
    {
        if (blank(config('services.google.client_id'))) {
            return;
        }

        foreach (GoogleCalendarAccount::query()->where('push_appointments', true)->get() as $account) {
            // Deleting an id the calendar never had returns 404 and is harmless;
            // the client treats an already-gone event as success rather than
            // retrying forever against a calendar that is simply not the one we
            // wrote to.
            $client->deleteEvent($account, $this->googleEventId);
        }
    }
}
