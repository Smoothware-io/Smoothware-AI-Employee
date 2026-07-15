<?php

use App\Enums\CompanyStatus;
use App\Enums\ImportStatus;
use App\Enums\RecordSource;
use App\Jobs\GenerateCompanyAnalysis;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Import;
use App\Models\User;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportCommitter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/** Stages a fixed 4-row CSV: one new, one dedup match, one invalid, one empty. */
function stagedImport(array $overrides = []): Import
{
    Storage::fake('local');

    $csv = implode("\n", [
        'name,domain,email,industry,first_name,last_name',
        'Acme BV,acme.nl,info@acme.nl,Retail,Jan,Jansen',
        'Existing Co,existing.nl,,Legal,,',           // dedupes onto the seeded company
        ',nodomain.nl,,,,',                            // invalid: no name
        ',,,,,',                                       // empty: skip
    ]);
    Storage::disk('local')->put('imports/test.csv', $csv);

    Company::factory()->create(['domain' => 'existing.nl']); // existing record for dedup

    $import = Import::factory()->create(array_merge(['path' => 'imports/test.csv'], $overrides));
    app(CsvImporter::class)->stage($import);

    return $import->fresh();
}

it('stages rows with create / match / skip / invalid dispositions and auto-maps columns', function () {
    $import = stagedImport();

    expect($import->status)->toBe(ImportStatus::Previewed)
        ->and($import->create_count)->toBe(1)
        ->and($import->match_count)->toBe(1)
        ->and($import->invalid_count)->toBe(1)
        ->and($import->skip_count)->toBe(1)
        ->and($import->column_mapping)->toHaveKeys(['name', 'domain', 'contact_first_name']);
});

it('commits: creates new companies with defaults + campaign, links matches, adds contacts, queues analysis', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $campaign = Campaign::factory()->create();

    $import = stagedImport([
        'default_owner_id' => $owner->id,
        'default_status' => CompanyStatus::Qualified->value,
        'campaign_id' => $campaign->id,
    ]);

    app(ImportCommitter::class)->commit($import);

    $acme = Company::firstWhere('name', 'Acme BV');
    expect($acme)->not->toBeNull()
        ->and($acme->source)->toBe(RecordSource::Import)
        ->and($acme->owner_id)->toBe($owner->id)
        ->and($acme->campaign_id)->toBe($campaign->id)
        ->and($acme->status)->toBe(CompanyStatus::Qualified)
        ->and($acme->industry)->toBe('Retail')
        ->and($acme->contacts()->where('first_name', 'Jan')->exists())->toBeTrue();

    // Existing Co was linked, not duplicated (seeded + Acme = 2 companies total).
    expect(Company::count())->toBe(2)
        ->and($import->fresh()->status)->toBe(ImportStatus::Completed);

    // Only the newly-created company queues a Phase-4 analysis.
    Queue::assertPushed(GenerateCompanyAnalysis::class, 1);
});

it('is idempotent — committing an already-completed import does nothing', function () {
    Queue::fake();
    $import = stagedImport();

    app(ImportCommitter::class)->commit($import);
    $count = Company::count();

    app(ImportCommitter::class)->commit($import->fresh());

    expect(Company::count())->toBe($count);
});
