<?php

use App\Models\Call;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Note;

/** The created-event attribute snapshot for a model, keyed by action. */
function createdAttributes(object $model, string $action): array
{
    return Event::query()
        ->where('entity_type', $model->getMorphClass())
        ->where('entity_id', $model->getKey())
        ->where('action', $action)
        ->firstOrFail()
        ->payload['attributes'];
}

it('never writes contact PII values to the event log', function () {
    $contact = Contact::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
    ]);

    $attrs = createdAttributes($contact, 'contact.created');

    expect($attrs['first_name'])->toBe(Contact::REDACTED)
        ->and($attrs['last_name'])->toBe(Contact::REDACTED)
        ->and($attrs['email'])->toBe(Contact::REDACTED)
        // Non-PII structural fields are retained.
        ->and($attrs)->toHaveKey('is_decision_maker');
});

it('never writes a note body to the event log', function () {
    $note = Note::factory()->create(['body' => 'Contains sensitive customer detail.']);

    $attrs = createdAttributes($note, 'note.created');

    expect($attrs['body'])->toBe(Note::REDACTED)
        ->and($attrs)->toHaveKey('category');
});

it('never writes call numbers or transcript to the event log', function () {
    $call = Call::factory()->withContent()->create();

    $attrs = createdAttributes($call, 'call.created');

    expect($attrs['from_number'])->toBe(Call::REDACTED)
        ->and($attrs['to_number'])->toBe(Call::REDACTED)
        ->and($attrs['transcript'])->toBe(Call::REDACTED)
        ->and($attrs['summary'])->toBe(Call::REDACTED);
});
