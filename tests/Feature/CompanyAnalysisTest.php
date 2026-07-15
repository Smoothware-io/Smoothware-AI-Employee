<?php

use App\Enums\AnalysisPriority;
use App\Enums\RecordSource;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\CompanyManualAnalysis;
use App\Models\KnowledgeEntry;
use App\Services\Analysis\CompanyAnalyzer;

it('generates an AI analysis with findings, per-finding confidence, and provenance', function () {
    KnowledgeEntry::factory()->published()->create([
        'title' => 'Web development & SEO',
        'body' => 'We build websites and improve SEO and offer hosting and AI chatbots.',
    ]);
    $company = Company::factory()->create(['domain' => 'devriesinterieur.nl', 'industry' => 'Retail']);

    $analysis = app(CompanyAnalyzer::class)->analyze($company);

    expect($analysis->source)->toBe(RecordSource::Ai)
        ->and($analysis->technical)->not->toBeEmpty()
        ->and($analysis->marketing)->not->toBeEmpty()
        ->and($analysis->recommendations)->not->toBeEmpty()
        ->and($analysis->technical[0])->toHaveKey('confidence')
        ->and($analysis->inferred_priority)->toBeInstanceOf(AnalysisPriority::class)
        ->and($analysis->source_context_version)->toContain('kb:')
        ->and($analysis->ai_run_id)->not->toBeNull();

    // A matching ops run was recorded, grounded in the KB.
    $run = AiRun::where('kind', 'analysis')->where('subject_id', $company->id)->firstOrFail();
    expect($run->uuid)->toBe($analysis->ai_run_id)
        ->and($run->grounded)->toBeTrue();
});

it('never touches the human-owned manual analysis', function () {
    $company = Company::factory()->create();
    $manual = CompanyManualAnalysis::factory()->for($company)->create([
        'priority' => AnalysisPriority::High,
        'pain_points' => 'Rep-noted pain point',
    ]);

    app(CompanyAnalyzer::class)->analyze($company);

    // The rep's record is byte-for-byte unchanged, and not duplicated.
    expect($company->manualAnalysis()->count())->toBe(1)
        ->and($manual->fresh()->priority)->toBe(AnalysisPriority::High)
        ->and($manual->fresh()->pain_points)->toBe('Rep-noted pain point');
});

it('regenerating adds a new analysis row (history), newest wins', function () {
    $company = Company::factory()->create();

    app(CompanyAnalyzer::class)->analyze($company);
    $second = app(CompanyAnalyzer::class)->analyze($company);

    expect($company->aiAnalyses()->count())->toBe(2)
        ->and($company->latestAiAnalysis->id)->toBe($second->id);
});
