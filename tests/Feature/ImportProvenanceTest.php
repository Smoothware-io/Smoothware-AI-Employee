<?php

use App\Enums\LawfulBasis;
use App\Models\Company;
use App\Models\Import;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportCommitter;
use Illuminate\Support\Facades\Storage;

/**
 * Provenance of imported personal data (GO-LIVE-LEGAL.md item #2). The question a
 * regulator asks is "where did this contact come from, and under what basis?" —
 * these tests assert that is answerable from the data.
 */
function importWithProvenance(array $attributes = []): Import
{
    Storage::fake('local');
    Storage::disk('local')->put('imports/p.csv', implode("\n", [
        'name,domain',
        'Bloom Florists,bloomflorists.nl',
    ]));

    return Import::create(array_merge([
        'original_name' => 'p.csv',
        'path' => 'imports/p.csv',
        'list_source' => 'Purchased from Acme Data BV, 2026-07-01, NL retail segment',
        'lawful_basis' => LawfulBasis::LegitimateInterest,
        'lawful_basis_notes' => 'LIA-2026-014',
    ], $attributes));
}

it('records where a list came from and under which basis', function () {
    $import = importWithProvenance();

    expect($import->refresh())
        ->list_source->toContain('Acme Data BV')
        ->lawful_basis->toBe(LawfulBasis::LegitimateInterest)
        ->lawful_basis_notes->toBe('LIA-2026-014');
});

it('traces an imported company back to its list source and lawful basis', function () {
    $import = importWithProvenance();
    app(CsvImporter::class)->stage($import);
    app(ImportCommitter::class)->commit($import->refresh());

    $company = Company::firstWhere('name', 'Bloom Florists');

    // company -> import_rows -> import is the audit path (no direct FK needed).
    $origin = $company->importRows()->first()->import;

    expect($origin->is($import))->toBeTrue()
        ->and($origin->list_source)->toContain('Acme Data BV')
        ->and($origin->lawful_basis)->toBe(LawfulBasis::LegitimateInterest);
});

it('flags a basis that needs an assessment when no reasoning was recorded', function () {
    expect(importWithProvenance(['lawful_basis_notes' => null])->hasUnjustifiedBasis())->toBeTrue();
    expect(importWithProvenance(['lawful_basis_notes' => 'LIA-2026-014'])->hasUnjustifiedBasis())->toBeFalse();

    // Consent carries no assessment burden, so absent notes are not a flag.
    expect(importWithProvenance([
        'lawful_basis' => LawfulBasis::Consent,
        'lawful_basis_notes' => null,
    ])->hasUnjustifiedBasis())->toBeFalse();
});

it('does not invent a lawful basis for imports that never recorded one', function () {
    // Null must stay null. Backfilling a default would fabricate a basis — the
    // exact failure the column exists to prevent.
    $import = Import::create(['original_name' => 'legacy.csv', 'path' => 'imports/legacy.csv']);

    expect($import->refresh())
        ->lawful_basis->toBeNull()
        ->list_source->toBeNull()
        ->and($import->hasUnjustifiedBasis())->toBeFalse();
});
