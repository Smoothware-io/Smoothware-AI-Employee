<?php

use App\Enums\AnalysisPriority;
use App\Enums\Language;
use App\Enums\PreferredChannel;
use App\Models\Company;
use App\Models\CompanyAiAnalysis;
use App\Models\CompanyManualAnalysis;
use App\Models\Contact;
use App\Models\Import;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportCommitter;
use App\Services\Outbound\CallInstructionBuilder;
use Illuminate\Support\Facades\Storage;

/**
 * Per-lead targeting: what a human decided to say to THIS company.
 *
 * It lands in the MANUAL analysis (Phase 4), which the AI may read and must
 * never overwrite — every lead has a different angle, and the person who found
 * the lead knows it better than a PageSpeed score does.
 */
function importSheet(string $csv): Import
{
    Storage::fake('local');
    Storage::disk('local')->put('imports/t.csv', $csv);

    $import = Import::create(['original_name' => 't.csv', 'path' => 'imports/t.csv']);
    app(CsvImporter::class)->stage($import);
    app(ImportCommitter::class)->commit($import->refresh());

    return $import->refresh();
}

// --- The sheet -> the manual analysis ---------------------------------------

it('turns per-lead targeting columns into a human-owned manual analysis', function () {
    importSheet(implode("\n", [
        'name,domain,pain_points,opportunities,notes,priority',
        'Acme BV,acme.nl,Verouderde website; geen SSL,Nieuwe website + SEO,Eigenaar is prijsbewust,high',
    ]));

    $analysis = Company::firstWhere('name', 'Acme BV')->manualAnalysis;

    expect($analysis)->not->toBeNull()
        ->and($analysis->pain_points)->toContain('Verouderde website')
        ->and($analysis->opportunities)->toContain('SEO')
        ->and($analysis->notes)->toContain('prijsbewust')
        ->and($analysis->priority)->toBe(AnalysisPriority::High);
});

it('gives each lead its own angle', function () {
    importSheet(implode("\n", [
        'name,pain_points,opportunities',
        'Geen Website BV,Heeft helemaal geen website,Eerste website',
        'Oude Site BV,Website is 8 jaar oud,Redesign',
        'Mail Graag BV,,Interesse in AI automation',
    ]));

    expect(Company::firstWhere('name', 'Geen Website BV')->manualAnalysis->pain_points)
        ->toContain('geen website')
        ->and(Company::firstWhere('name', 'Oude Site BV')->manualAnalysis->pain_points)
        ->toContain('8 jaar oud')
        ->and(Company::firstWhere('name', 'Mail Graag BV')->manualAnalysis->opportunities)
        ->toContain('AI automation');
});

it('creates no manual analysis when the sheet carries no targeting', function () {
    importSheet("name,domain\nPlain BV,plain.nl");

    // An empty analysis row would look like a rep considered this lead and had
    // nothing to say. They never looked.
    expect(Company::firstWhere('name', 'Plain BV')->manualAnalysis)->toBeNull();
});

it('never lets a spreadsheet overwrite what a rep already wrote', function () {
    $company = Company::factory()->create(['name' => 'Acme BV', 'domain' => 'acme.nl']);
    CompanyManualAnalysis::factory()->for($company)->create([
        'pain_points' => 'Wat de rep zelf ontdekte',
        'opportunities' => null,
    ]);

    // Re-importing the same company must ADD to the blanks, never replace.
    importSheet(implode("\n", [
        'name,domain,pain_points,opportunities',
        'Acme BV,acme.nl,Iets uit een sheet,Nieuwe website',
    ]));

    $analysis = $company->fresh()->manualAnalysis;

    expect($analysis->pain_points)->toBe('Wat de rep zelf ontdekte')  // untouched
        ->and($analysis->opportunities)->toBe('Nieuwe website');       // blank was filled
});

it('imports language and country and drops what it cannot read', function () {
    importSheet(implode("\n", [
        'name,country,language,city',
        'Dutch BV,Netherlands,nl,Utrecht',
        'Belgian BV,BE,,Gent',
        'Odd BV,Atlantis,klingon,Nowhere',
    ]));

    expect(Company::firstWhere('name', 'Dutch BV')->country)->toBe('NL')
        ->and(Company::firstWhere('name', 'Dutch BV')->language)->toBe(Language::Dutch)
        ->and(Company::firstWhere('name', 'Dutch BV')->city)->toBe('Utrecht')
        // BE is bilingual: no language given, and we refuse to guess.
        ->and(Company::firstWhere('name', 'Belgian BV')->country)->toBe('BE')
        ->and(Company::firstWhere('name', 'Belgian BV')->spokenLanguage())->toBeNull()
        // Unreadable country keeps the column default rather than storing junk.
        ->and(Company::firstWhere('name', 'Odd BV')->country)->toBe('NL')
        ->and(Company::firstWhere('name', 'Odd BV')->language)->toBeNull();
});

// --- What the AI is told about this lead ------------------------------------

it('tells the AI what our team said about this specific lead', function () {
    $company = Company::factory()->create(['name' => 'Acme BV']);
    CompanyManualAnalysis::factory()->for($company)->create([
        'pain_points' => 'Verouderde website uit 2016',
        'opportunities' => 'Redesign + SEO',
        'notes' => 'Eigenaar wil lokaal groeien',
        'priority' => AnalysisPriority::High,
    ]);

    $built = app(CallInstructionBuilder::class)->forCompany($company);

    expect($built['instructions'])->toContain('WAT ONS TEAM OVER DEZE LEAD ZEGT')
        ->and($built['instructions'])->toContain('Verouderde website uit 2016')
        ->and($built['instructions'])->toContain('lokaal groeien')
        // Named as human-written, so the model treats it as steering, not trivia.
        ->and($built['instructions'])->toContain('door een mens geschreven');
});

it('tells the AI the human wins where they disagree with the machine', function () {
    // Principle #2, on a live call: no review queue, no undo. If the AI argues
    // its own PageSpeed number over a rep who has met these people, the rep's
    // judgment was silently overridden — which is the thing this whole project
    // is built to prevent.
    $company = Company::factory()->create();
    CompanyManualAnalysis::factory()->for($company)->create(['pain_points' => 'Site is prima, hun probleem is leads']);
    CompanyAiAnalysis::factory()->for($company)->create([
        'technical' => [['key' => 'pagespeed', 'label' => 'PageSpeed', 'assessment' => 'Score 42/100']],
    ]);

    $built = app(CallInstructionBuilder::class)->forCompany($company);

    expect($built['instructions'])->toContain('volg ons team');
});

it('does not claim a disagreement rule when only one side exists', function () {
    $company = Company::factory()->create();
    CompanyAiAnalysis::factory()->for($company)->create([
        'technical' => [['key' => 'ssl', 'label' => 'SSL', 'assessment' => 'Valid']],
    ]);

    expect(app(CallInstructionBuilder::class)->forCompany($company)['instructions'])
        ->not->toContain('volg ons team');
});

it('acknowledges a contact who would rather have email', function () {
    $company = Company::factory()->create();
    Contact::factory()->for($company)->create(['preferred_channel' => PreferredChannel::Email]);

    // The preference says HOW, not WHETHER — but phoning someone who asked for
    // email without acknowledging it is how people end up suppressed.
    expect(app(CallInstructionBuilder::class)->forCompany($company)['instructions'])
        ->toContain('liever per e-mail');
});

it('tells the AI which language to speak, and to ask when we do not know', function () {
    $dutch = Company::factory()->create(['language' => Language::Dutch]);
    $unknown = Company::factory()->create(['language' => null, 'country' => 'BE']);

    expect(app(CallInstructionBuilder::class)->forCompany($dutch)['instructions'])
        ->toContain('Spreek Nederlands')
        ->and(app(CallInstructionBuilder::class)->forCompany($unknown)['instructions'])
        ->toContain('vraag welke taal');
});
