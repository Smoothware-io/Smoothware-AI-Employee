<?php

use App\Enums\ImportRowDisposition;
use App\Enums\SuppressionSource;
use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Import;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportCommitter;
use App\Services\SuppressionList;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

/**
 * The do-not-contact list. GO-LIVE-LEGAL #2 — the right to object is ABSOLUTE
 * for direct marketing (Art. 21(2)): no balancing, no legitimate interest.
 *
 * The failure mode here is silence. A missed match doesn't error, doesn't fail
 * anything, doesn't look wrong — we just call someone who told us not to. So
 * normalisation is tested far harder than anything else in this suite.
 */
function suppressions(): SuppressionList
{
    return app(SuppressionList::class);
}

// --- Normalisation: the part that fails silently ---------------------------

it('treats every way of writing the same Dutch mobile as one number', function (string $written) {
    suppressions()->suppress(SuppressionType::Phone, '+31612345678');

    // A rep types whatever they see. All of these are that same phone.
    expect(suppressions()->isSuppressed(phone: $written))->toBeTrue("'{$written}' must match");
})->with([
    '+31612345678',
    '+31 6 1234 5678',
    '+31 (0)6 12345678',
    '0031612345678',
    '0031 6 1234 5678',
    '0612345678',
    '06-12345678',
    '06 12 34 56 78',
    '(06) 1234 5678',
    '31612345678',
    // The one that actually broke: "(0)" marks the trunk prefix you drop when
    // dialling from abroad. Strip digits naively and +31 (0)6… becomes 3106…,
    // a different number that silently never matches.
    '+31(0)612345678',
    '+31 (0) 6 1234 5678',
    '0031 (0)6 12345678',
]);

it('never lets the Dutch trunk-zero notation produce a different number', function () {
    // Same phone, three notations. If any pair disagrees, a suppression misses.
    $list = suppressions();

    $canonical = $list->normalize(SuppressionType::Phone, '+31612345678');

    expect($list->normalize(SuppressionType::Phone, '+31 (0)6 12345678'))->toBe($canonical)
        ->and($list->normalize(SuppressionType::Phone, '06 12345678'))->toBe($canonical)
        ->and($list->normalize(SuppressionType::Phone, '0031(0)612345678'))->toBe($canonical)
        ->and($canonical)->toBe('31612345678');
});

it('normalises a landline the same way', function (string $written) {
    suppressions()->suppress(SuppressionType::Phone, '+31201234567');

    expect(suppressions()->isSuppressed(phone: $written))->toBeTrue("'{$written}' must match");
})->with([
    '+31201234567',
    '020-1234567',
    '020 123 45 67',
    '0031201234567',
]);

it('does not match a different number', function () {
    suppressions()->suppress(SuppressionType::Phone, '+31612345678');

    expect(suppressions()->isSuppressed(phone: '+31612345679'))->toBeFalse()
        ->and(suppressions()->isSuppressed(phone: '0612345679'))->toBeFalse();
});

it('normalises email case and whitespace but is not clever about it', function () {
    suppressions()->suppress(SuppressionType::Email, 'Eva@Acme.NL');

    expect(suppressions()->isSuppressed(email: 'eva@acme.nl'))->toBeTrue()
        ->and(suppressions()->isSuppressed(email: '  EVA@ACME.NL  '))->toBeTrue()
        // NOT normalised away: +tags and dots are provider-specific. Treating
        // eva+crm@ as eva@ would suppress an address they never gave us.
        ->and(suppressions()->isSuppressed(email: 'eva+crm@acme.nl'))->toBeFalse();
});

it('normalises a domain out of whatever form it arrives in', function (string $written) {
    suppressions()->suppress(SuppressionType::Domain, 'acme.nl');

    expect(suppressions()->isSuppressed(domain: $written))->toBeTrue("'{$written}' must match");
})->with([
    'acme.nl',
    'ACME.NL',
    'www.acme.nl',
    'https://acme.nl',
    'https://www.acme.nl/contact',
    'http://acme.nl:8080/x',
]);

it('refuses to store something it could never match', function () {
    expect(suppressions()->suppress(SuppressionType::Phone, 'not a phone'))->toBeNull()
        ->and(suppressions()->suppress(SuppressionType::Email, 'no-at-sign'))->toBeNull()
        ->and(suppressions()->suppress(SuppressionType::Phone, ''))->toBeNull()
        ->and(Suppression::count())->toBe(0);
});

// --- Scope of an objection --------------------------------------------------

it('lets a domain suppression cover a person whose number we never listed', function () {
    suppressions()->suppress(SuppressionType::Domain, 'acme.nl', reason: 'Asked us to stop entirely');

    $company = Company::factory()->create(['domain' => 'acme.nl']);
    $contact = Contact::factory()->for($company)->create(['phone' => '+31612345678']);

    // "Never contact anyone here again" means this person too.
    expect(suppressions()->isContactSuppressed($contact))->toBeTrue()
        ->and(suppressions()->isCompanySuppressed($company))->toBeTrue();
});

it('infers the domain from an email address', function () {
    suppressions()->suppress(SuppressionType::Domain, 'acme.nl');

    // Only the address was given, but it belongs to a suppressed company.
    expect(suppressions()->isSuppressed(email: 'someone@acme.nl'))->toBeTrue();
});

it('does not suppress an unrelated company', function () {
    suppressions()->suppress(SuppressionType::Domain, 'acme.nl');

    $other = Company::factory()->create(['domain' => 'other.nl', 'phone' => '+31201111111']);

    expect(suppressions()->isCompanySuppressed($other))->toBeFalse();
});

// --- Durability: the point of the whole table -------------------------------

it('survives the erasure of the contact it protects', function () {
    // The sequence this prevents: "delete my data and never call me again" ->
    // we honour the erasure -> the objection disappears with it -> we re-import
    // the same list next month and call them. The erasure caused the violation.
    $company = Company::factory()->create(['domain' => 'acme.nl']);
    $contact = Contact::factory()->for($company)->create(['phone' => '+31612345678']);

    suppressions()->suppress(SuppressionType::Phone, $contact->phone, SuppressionSource::Call);

    $contact->forceDelete();
    $company->forceDelete();

    expect(suppressions()->isSuppressed(phone: '+31612345678'))->toBeTrue()
        ->and(Suppression::count())->toBe(1);
});

it('is idempotent when a rep is told twice', function () {
    $first = suppressions()->suppress(SuppressionType::Phone, '+31612345678');
    $second = suppressions()->suppress(SuppressionType::Phone, '06 12345678');

    // Doing the right thing twice must not throw.
    expect($second->id)->toBe($first->id)
        ->and(Suppression::count())->toBe(1);
});

it('enforces one active suppression per address at the database', function () {
    suppressions()->suppress(SuppressionType::Phone, '+31612345678');

    expect(fn () => Suppression::create([
        'type' => SuppressionType::Phone,
        'value_normalized' => '31612345678',
        'source' => SuppressionSource::Manual,
    ]))->toThrow(QueryException::class);
});

// --- Releasing --------------------------------------------------------------

it('releases rather than deletes, and records who and why', function () {
    $user = User::factory()->create();
    $entry = suppressions()->suppress(SuppressionType::Phone, '+31612345678');

    $entry->release('Added by mistake — wrong number', $user->id);

    expect(suppressions()->isSuppressed(phone: '+31612345678'))->toBeFalse()
        // The row STAYS. "Who let us call this person again?" must be answerable.
        ->and(Suppression::count())->toBe(1)
        ->and($entry->fresh()->released_reason)->toContain('mistake')
        ->and($entry->fresh()->released_by)->toBe($user->id);
});

it('allows re-suppression after a release', function () {
    $entry = suppressions()->suppress(SuppressionType::Phone, '+31612345678');
    $entry->release('mistake');

    $again = suppressions()->suppress(SuppressionType::Phone, '+31612345678');

    expect($again)->not->toBeNull()
        ->and($again->id)->not->toBe($entry->id)
        ->and(suppressions()->isSuppressed(phone: '+31612345678'))->toBeTrue();
});

it('keeps the suppressed address out of the append-only audit log', function () {
    suppressions()->suppress(SuppressionType::Phone, '+31612345678');

    $event = Event::where('entity_type', Suppression::class)->latest('id')->first();

    // The log records THAT a suppression happened, never whose number it was.
    expect($event)->not->toBeNull()
        ->and($event->payload['attributes']['value_normalized'])->toBe('[redacted]')
        ->and($event->payload['attributes']['value_raw'])->toBe('[redacted]');
});

// --- The import gate --------------------------------------------------------

it('flags a suppressed row in the preview and never commits it', function () {
    suppressions()->suppress(SuppressionType::Phone, '+31612345678', SuppressionSource::Call, 'Told us to stop');

    Storage::fake('local');
    Storage::disk('local')->put('imports/s.csv', implode("\n", [
        'name,phone,domain',
        'Acme BV,06-12345678,acme.nl',       // suppressed
        'Nova Legal,+31201111111,novalegal.nl', // fine
    ]));

    $import = Import::create(['original_name' => 's.csv', 'path' => 'imports/s.csv']);
    app(CsvImporter::class)->stage($import);
    $import->refresh();

    expect($import->suppressed_count)->toBe(1)
        ->and($import->create_count)->toBe(1);

    $suppressedRow = $import->rows()->where('disposition', ImportRowDisposition::Suppressed)->first();
    expect($suppressedRow->errors['suppressed'])->toContain('do-not-contact');

    app(ImportCommitter::class)->commit($import->refresh());

    // The company that objected is never created, however the list was written.
    expect(Company::firstWhere('name', 'Acme BV'))->toBeNull()
        ->and(Company::firstWhere('name', 'Nova Legal'))->not->toBeNull();
});

it('suppresses an imported row by its domain, not just its number', function () {
    suppressions()->suppress(SuppressionType::Domain, 'acme.nl');

    Storage::fake('local');
    Storage::disk('local')->put('imports/d.csv', implode("\n", [
        'name,phone,domain',
        'Acme BV,+31999999999,www.acme.nl',
    ]));

    $import = Import::create(['original_name' => 'd.csv', 'path' => 'imports/d.csv']);
    app(CsvImporter::class)->stage($import);

    expect($import->refresh()->suppressed_count)->toBe(1);
});
