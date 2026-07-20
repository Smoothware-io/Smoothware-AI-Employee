<?php

use App\Enums\AppointmentStatus;
use App\Jobs\PushAppointmentToGoogle;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Call;
use App\Models\Company;
use App\Models\GoogleCalendarAccount;
use App\Models\User;
use App\Services\Availability\AvailabilityCalculator;
use App\Services\Google\GoogleCalendarClient;
use App\Services\Voice\VoiceToolRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The AI must not offer a time the rep is already busy in Google — and must not
 * stop working when Google does.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00')); // Monday
    Cache::flush();

    config([
        'services.google.client_id' => 'test-client',
        'services.google.client_secret' => 'test-secret',
        'services.google.redirect' => 'https://crm.test/google/calendar/callback',
    ]);

    AvailabilityRule::create([
        'weekday' => 1, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00', 'is_active' => true,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function googleAccount(array $overrides = []): GoogleCalendarAccount
{
    return GoogleCalendarAccount::create(array_merge([
        'user_id' => User::factory()->create()->getKey(),
        'google_email' => 'rep@smoothware.io',
        'access_token' => 'valid-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'calendar_id' => 'primary',
        'block_from_busy' => true,
    ], $overrides));
}

function fakeBusy(array $periods): void
{
    Http::fake([
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => ['primary' => ['busy' => $periods]],
        ]),
    ]);
}

it('does not offer a time the rep is busy in Google', function () {
    googleAccount();
    fakeBusy([
        ['start' => '2026-07-20T10:00:00+00:00', 'end' => '2026-07-20T11:00:00+00:00'],
    ]);

    $slots = collect(app(AvailabilityCalculator::class)->freeSlots())
        ->map(fn (Carbon $s): string => $s->format('Y-m-d H:i'));

    expect($slots)->toContain('2026-07-20 09:00')
        ->and($slots)->not->toContain('2026-07-20 10:00')
        ->and($slots)->not->toContain('2026-07-20 10:30')
        ->and($slots)->toContain('2026-07-20 11:00');
});

it('refuses to confirm a time that is busy in Google', function () {
    googleAccount();
    fakeBusy([
        ['start' => '2026-07-20T10:00:00+00:00', 'end' => '2026-07-20T11:00:00+00:00'],
    ]);

    expect(app(AvailabilityCalculator::class)->isFree(Carbon::parse('2026-07-20 10:00'), 30))
        ->toBeFalse();
});

it('keeps booking when Google is unreachable', function () {
    // Deliberate fail-open. Refusing every booking because Google is down is a
    // worse, more visible failure than occasionally offering a busy slot the rep
    // can decline — and the CRM's own appointments still prevent double-booking.
    googleAccount();
    Http::fake(['www.googleapis.com/*' => Http::response([], 500)]);

    expect(app(AvailabilityCalculator::class)->freeSlots())->not->toBeEmpty();
});

it('ignores a calendar the rep has switched off', function () {
    googleAccount(['block_from_busy' => false]);
    fakeBusy([
        ['start' => '2026-07-20T10:00:00+00:00', 'end' => '2026-07-20T11:00:00+00:00'],
    ]);

    $slots = collect(app(AvailabilityCalculator::class)->freeSlots())
        ->map(fn (Carbon $s): string => $s->format('Y-m-d H:i'));

    expect($slots)->toContain('2026-07-20 10:00');
});

it('does nothing at all when Google is not configured', function () {
    config(['services.google.client_id' => null]);
    googleAccount();
    Http::fake();

    expect(app(AvailabilityCalculator::class)->freeSlots())->not->toBeEmpty();
    Http::assertNothingSent();
});

it('caches busy periods so a chatty call does not hammer Google', function () {
    googleAccount();
    fakeBusy([['start' => '2026-07-20T10:00:00+00:00', 'end' => '2026-07-20T11:00:00+00:00']]);

    $calc = app(AvailabilityCalculator::class);
    $calc->freeSlots();
    $calc->freeSlots();

    Http::assertSentCount(1);
});

it('refreshes an expired access token before asking for busy times', function () {
    googleAccount(['access_token' => 'stale', 'expires_at' => now()->subMinutes(5)]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => []]]]),
    ]);

    app(AvailabilityCalculator::class)->freeSlots();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token'));
});

it('marks the connection as needing attention when the refresh is rejected', function () {
    // A dead connection looks exactly like an empty calendar from the outside.
    // Saying so is what stops it silently never blocking anything again.
    $account = googleAccount(['access_token' => 'stale', 'expires_at' => now()->subMinutes(5)]);

    Http::fake(['oauth2.googleapis.com/token' => Http::response([], 400)]);

    app(AvailabilityCalculator::class)->freeSlots();

    expect($account->fresh()->last_error)->toContain('reconnect');
});

it('queues the meeting to Google rather than pushing it during the call', function () {
    Queue::fake();
    config(['voice.service_token' => 'test-secret-token']);

    $company = Company::factory()->create();
    $call = Call::create([
        'company_id' => $company->getKey(),
        'direction' => 'inbound',
        'status' => 'in_progress',
        'external_provider' => 'openai-realtime',
        'external_id' => 'rtc_google',
        'started_at' => now(),
    ]);

    app(VoiceToolRegistry::class)->execute('book_appointment', [
        'starts_at' => Carbon::parse('2026-07-20 10:00')->toIso8601String(),
        'title' => 'Intro call',
    ], $call);

    Queue::assertPushed(PushAppointmentToGoogle::class);
});

it('never pushes the same meeting twice', function () {
    $account = googleAccount();
    $company = Company::factory()->create();

    $appointment = Appointment::create([
        'company_id' => $company->getKey(),
        'title' => 'Already there',
        'starts_at' => Carbon::parse('2026-07-20 10:00'),
        'ends_at' => Carbon::parse('2026-07-20 10:30'),
        'status' => AppointmentStatus::Scheduled,
        'google_event_id' => 'existing-event',
    ]);

    Http::fake();

    (new PushAppointmentToGoogle($appointment->getKey()))
        ->handle(app(GoogleCalendarClient::class));

    // A retry that creates a second event puts a duplicate in a real calendar.
    Http::assertNothingSent();
    expect($account->fresh())->not->toBeNull();
});
