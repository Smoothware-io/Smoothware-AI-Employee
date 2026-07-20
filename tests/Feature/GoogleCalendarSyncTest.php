<?php

use App\Enums\AppointmentStatus;
use App\Jobs\PushAppointmentToGoogle;
use App\Jobs\RemoveAppointmentFromGoogle;
use App\Models\Appointment;
use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Ownership: the CRM owns the RECORD, Google owns the PERSON'S TIME.
 *
 * The failure these prevent is not a loud conflict — it is quiet rot. A meeting
 * cancelled in the CRM but left behind in Google keeps being reported as busy,
 * so the AI slowly runs out of availability with nothing to show why.
 */
beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00'));

    config(['services.google.client_id' => 'test-client']);
});

afterEach(fn () => Carbon::setTestNow());

function appointment(array $overrides = []): Appointment
{
    return Appointment::create(array_merge([
        'company_id' => Company::factory()->create()->getKey(),
        'title' => 'Intro call',
        'starts_at' => Carbon::parse('2026-07-21 10:00'),
        'ends_at' => Carbon::parse('2026-07-21 10:30'),
        'status' => AppointmentStatus::Scheduled,
    ], $overrides));
}

it('pushes a manually created appointment to Google too', function () {
    // Not only the AI's bookings: a rep typing one in must block the same slot.
    appointment();

    Queue::assertPushed(PushAppointmentToGoogle::class);
});

it('withdraws the event from Google when the meeting is cancelled', function () {
    $a = appointment(['google_event_id' => 'evt_123']);

    $a->update(['status' => AppointmentStatus::Cancelled]);

    Queue::assertPushed(
        RemoveAppointmentFromGoogle::class,
        fn (RemoveAppointmentFromGoogle $job): bool => $job->googleEventId === 'evt_123',
    );
});

it('forgets the Google event once withdrawn, so rescheduling makes a fresh one', function () {
    $a = appointment(['google_event_id' => 'evt_123', 'google_html_link' => 'https://cal']);

    $a->update(['status' => AppointmentStatus::Cancelled]);

    expect($a->fresh()->google_event_id)->toBeNull()
        ->and($a->fresh()->google_html_link)->toBeNull();
});

it('withdraws the event when the appointment is deleted outright', function () {
    $a = appointment(['google_event_id' => 'evt_456']);

    $a->delete();

    Queue::assertPushed(RemoveAppointmentFromGoogle::class);
});

it('does nothing in Google for a change that is not a cancellation', function () {
    $a = appointment(['google_event_id' => 'evt_789']);
    Queue::fake(); // reset the create-time push

    $a->update(['title' => 'Renamed but still happening']);

    Queue::assertNotPushed(RemoveAppointmentFromGoogle::class);
    expect($a->fresh()->google_event_id)->toBe('evt_789');
});

it('never pushes an appointment that was created already cancelled', function () {
    Queue::fake();

    appointment(['status' => AppointmentStatus::Cancelled]);

    Queue::assertNotPushed(PushAppointmentToGoogle::class);
});
