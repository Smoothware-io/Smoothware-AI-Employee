<?php

use App\Enums\RecordSource;
use App\Models\Appointment;
use App\Models\Call;
use App\Models\Company;
use App\Models\Note;

/**
 * The seam go-voice calls to give the AI hands. These prove the two things that
 * matter: nobody unauthenticated may act, and an authorised tool call actually
 * writes to the CRM with AI provenance.
 */
beforeEach(function () {
    config(['voice.service_token' => 'test-secret-token']);

    $this->company = Company::factory()->create();
    $this->call = Call::create([
        'company_id' => $this->company->getKey(),
        'direction' => 'inbound',
        'status' => 'in_progress',
        'external_provider' => 'openai-realtime',
        'external_id' => 'rtc_test_123',
        'started_at' => now(),
    ]);
});

function tool(array $payload, ?string $token = 'test-secret-token')
{
    $headers = $token ? ['Authorization' => "Bearer {$token}"] : [];

    return test()->postJson('/api/voice/tool', $payload, $headers);
}

it('rejects a tool call with no token', function () {
    tool(['call_id' => 'rtc_test_123', 'name' => 'add_note'], token: null)
        ->assertStatus(401);
});

it('rejects a tool call with the wrong token', function () {
    tool(['call_id' => 'rtc_test_123', 'name' => 'add_note'], token: 'wrong')
        ->assertStatus(401);
});

it('refuses entirely when no service token is configured', function () {
    // Fail-closed: a write endpoint must never be open because a secret is unset.
    config(['voice.service_token' => '']);

    tool(['call_id' => 'rtc_test_123', 'name' => 'add_note'])
        ->assertStatus(503);
});

it('adds a note against the call\'s company, tagged AI', function () {
    tool([
        'call_id' => 'rtc_test_123',
        'name' => 'add_note',
        'arguments' => ['body' => 'Caller wants a callback next week.'],
    ])->assertOk()->assertJsonPath('output', fn ($o) => str_contains($o, 'saved'));

    $note = Note::where('company_id', $this->company->getKey())->first();
    expect($note)->not->toBeNull()
        ->and($note->body)->toBe('Caller wants a callback next week.')
        // AI-created, so it renders distinct from human notes (principle #2).
        ->and($note->source)->toBe(RecordSource::Ai);
});

it('books an appointment for a future time, tagged AI', function () {
    $when = now()->addDays(2)->setTime(14, 0);

    tool([
        'call_id' => 'rtc_test_123',
        'name' => 'book_appointment',
        'arguments' => ['starts_at' => $when->toIso8601String(), 'title' => 'Intro call'],
    ])->assertOk()->assertJsonPath('output', fn ($o) => str_contains($o, 'booked'));

    $appt = Appointment::where('company_id', $this->company->getKey())->first();
    expect($appt)->not->toBeNull()
        ->and($appt->title)->toBe('Intro call')
        ->and($appt->source)->toBe(RecordSource::Ai)
        ->and($appt->starts_at->toIso8601String())->toBe($when->toIso8601String());
});

it('refuses to book a time in the past', function () {
    tool([
        'call_id' => 'rtc_test_123',
        'name' => 'book_appointment',
        'arguments' => ['starts_at' => now()->subDay()->toIso8601String(), 'title' => 'Nope'],
    ])->assertOk()->assertJsonPath('output', fn ($o) => str_contains($o, 'error'));

    expect(Appointment::count())->toBe(0);
});

it('returns available slots without booking anything', function () {
    tool([
        'call_id' => 'rtc_test_123',
        'name' => 'get_available_times',
        'arguments' => [],
    ])->assertOk()->assertJsonPath('output', fn ($o) => str_contains($o, 'available'));

    expect(Appointment::count())->toBe(0);
});

it('returns a graceful error for an unknown tool rather than throwing', function () {
    tool([
        'call_id' => 'rtc_test_123',
        'name' => 'launch_rockets',
        'arguments' => [],
    ])->assertOk()->assertJsonPath('output', fn ($o) => str_contains($o, 'unknown tool'));
});

it('accepts arguments as a JSON string, the way OpenAI sends them', function () {
    tool([
        'call_id' => 'rtc_test_123',
        'name' => 'add_note',
        'arguments' => json_encode(['body' => 'From a JSON string.']),
    ])->assertOk();

    expect(Note::where('body', 'From a JSON string.')->exists())->toBeTrue();
});

/**
 * A stranger ringing the number has no company until a human matches them. That
 * is the normal inbound case, and on the first real call the AI correctly refused
 * to book because of it — safe, but the lead's request died at hang-up.
 */
it('files work from an unknown caller against the fallback company', function () {
    $orphan = Call::create([
        'direction' => 'inbound',
        'status' => 'in_progress',
        'external_provider' => 'openai-realtime',
        'external_id' => 'rtc_no_company',
        'started_at' => now(),
    ]);

    tool([
        'call_id' => 'rtc_no_company',
        'name' => 'add_note',
        'arguments' => ['body' => 'Wants a mobile app.'],
    ])->assertOk()->assertJsonPath('output', fn ($o) => str_contains($o, 'saved'));

    $fallback = Company::where('name', config('voice.fallback_company.name'))->first();
    expect($fallback)->not->toBeNull()
        // A filing cabinet the system opened, not the AI claiming this company
        // exists in the world.
        ->and($fallback->source)->toBe(RecordSource::System);

    // The call is linked to it too, so the note and the call agree rather than
    // leaving a human two orphans to reconcile.
    expect($orphan->fresh()->company_id)->toBe($fallback->getKey());
    expect(Note::where('company_id', $fallback->getKey())->exists())->toBeTrue();
});

it('reuses one fallback company rather than creating one per call', function () {
    foreach (['rtc_a', 'rtc_b'] as $id) {
        Call::create([
            'direction' => 'inbound',
            'status' => 'in_progress',
            'external_provider' => 'openai-realtime',
            'external_id' => $id,
            'started_at' => now(),
        ]);

        tool(['call_id' => $id, 'name' => 'add_note', 'arguments' => ['body' => 'hi']])->assertOk();
    }

    expect(Company::where('name', config('voice.fallback_company.name'))->count())->toBe(1);
});

it('still refuses when the fallback is switched off', function () {
    config(['voice.fallback_company.enabled' => false]);

    Call::create([
        'direction' => 'inbound',
        'status' => 'in_progress',
        'external_provider' => 'openai-realtime',
        'external_id' => 'rtc_strict',
        'started_at' => now(),
    ]);

    tool(['call_id' => 'rtc_strict', 'name' => 'add_note', 'arguments' => ['body' => 'x']])
        ->assertOk()->assertJsonPath('output', fn ($o) => str_contains($o, 'error'));

    expect(Note::count())->toBe(0);
});
