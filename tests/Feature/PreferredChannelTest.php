<?php

use App\Enums\PreferredChannel;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Import;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportCommitter;
use Illuminate\Support\Facades\Storage;

/**
 * How a contact prefers to be reached. It lives on Contact (a person has a
 * preference; a company does not), is nullable (null = never stated), and is
 * advisory — it says HOW someone would rather be contacted, never WHETHER we may.
 */
it('is null until someone actually records it', function () {
    // No default: defaulting to "either" would let automation read "we never
    // asked" as consent to any channel.
    expect(Contact::factory()->create()->preferred_channel)->toBeNull();
});

it('is editable manually', function () {
    $contact = Contact::factory()->create(['preferred_channel' => PreferredChannel::Email]);

    expect($contact->refresh()->preferred_channel)->toBe(PreferredChannel::Email);

    $contact->update(['preferred_channel' => PreferredChannel::Phone]);

    expect($contact->refresh()->preferred_channel)->toBe(PreferredChannel::Phone);
});

it('answers which channels a preference permits', function () {
    expect(PreferredChannel::Email->allowsEmail())->toBeTrue()
        ->and(PreferredChannel::Email->allowsPhone())->toBeFalse()
        ->and(PreferredChannel::Phone->allowsPhone())->toBeTrue()
        ->and(PreferredChannel::Phone->allowsEmail())->toBeFalse()
        ->and(PreferredChannel::Either->allowsPhone())->toBeTrue()
        ->and(PreferredChannel::Either->allowsEmail())->toBeTrue();
});

it('normalises the free text an imported list actually contains', function (?string $raw, ?PreferredChannel $expected) {
    expect(PreferredChannel::fromImport($raw))->toBe($expected);
})->with([
    ['phone', PreferredChannel::Phone],
    ['Phone', PreferredChannel::Phone],
    ['  TEL  ', PreferredChannel::Phone],
    ['call', PreferredChannel::Phone],
    ['email', PreferredChannel::Email],
    ['E-Mail', PreferredChannel::Email],
    ['either', PreferredChannel::Either],
    ['both', PreferredChannel::Either],
    ['no preference', PreferredChannel::Either],
    // Anything we don't recognise stays null: inventing a preference the person
    // never stated is worse than having none.
    ['carrier pigeon', null],
    ['', null],
    [null, null],
]);

it('sets the channel from a CSV column during import', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/c.csv', implode("\n", [
        'name,first_name,last_name,preferred contact method',
        'Bloom Florists,Eva,Bloom,email',
        'De Vries BV,Jan,de Vries,CALL',
        'Nova Legal,Nova,Legal,carrier pigeon',
    ]));

    $import = Import::create(['original_name' => 'c.csv', 'path' => 'imports/c.csv']);
    app(CsvImporter::class)->stage($import);
    app(ImportCommitter::class)->commit($import->refresh());

    $byCompany = fn (string $name) => Company::firstWhere('name', $name)->contacts()->first();

    expect($byCompany('Bloom Florists')->preferred_channel)->toBe(PreferredChannel::Email)
        ->and($byCompany('De Vries BV')->preferred_channel)->toBe(PreferredChannel::Phone)
        // Unrecognised source data -> no preference, rather than a guess.
        ->and($byCompany('Nova Legal')->preferred_channel)->toBeNull();
});

it('leaves the channel null when the CSV has no such column', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/n.csv', implode("\n", [
        'name,first_name',
        'Acme BV,Jan',
    ]));

    $import = Import::create(['original_name' => 'n.csv', 'path' => 'imports/n.csv']);
    app(CsvImporter::class)->stage($import);
    app(ImportCommitter::class)->commit($import->refresh());

    expect(Company::firstWhere('name', 'Acme BV')->contacts()->first()->preferred_channel)->toBeNull();
});

it('drops a channel hint on a row that creates no contact', function () {
    // No person, no personal preference — the hint has nowhere to live. Asserted
    // so the behaviour is a decision rather than a surprise.
    Storage::fake('local');
    Storage::disk('local')->put('imports/nc.csv', implode("\n", [
        'name,preferred channel',
        'Faceless BV,email',
    ]));

    $import = Import::create(['original_name' => 'nc.csv', 'path' => 'imports/nc.csv']);
    app(CsvImporter::class)->stage($import);
    app(ImportCommitter::class)->commit($import->refresh());

    $company = Company::firstWhere('name', 'Faceless BV');

    expect($company)->not->toBeNull()
        ->and($company->contacts()->count())->toBe(0);
});

it('keeps the preference value in the audit log, unlike identifying fields', function () {
    // preferred_channel is a low-risk categorical and the event already names the
    // contact by id, so the changed VALUE is the useful part of the audit.
    $contact = Contact::factory()->create();
    $contact->update(['preferred_channel' => PreferredChannel::Email]);

    $event = Event::where('entity_type', Contact::class)
        ->where('entity_id', $contact->getKey())
        ->where('action', 'contact.updated')
        ->latest('id')
        ->first();

    expect($event->payload['after']['preferred_channel'])->toBe('email')
        ->and($event->payload['after'])->not->toHaveKey('email'); // identifiers still redacted
});
