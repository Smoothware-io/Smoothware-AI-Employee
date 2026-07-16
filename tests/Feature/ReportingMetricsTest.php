<?php

use App\Enums\AnalysisPriority;
use App\Enums\CompanyStatus;
use App\Enums\ImportStatus;
use App\Enums\LawfulBasis;
use App\Enums\RecordSource;
use App\Enums\TaskStatus;
use App\Models\AiAction;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\CompanyAiAnalysis;
use App\Models\CompanyManualAnalysis;
use App\Models\Import;
use App\Models\Task;
use App\Models\User;
use App\Services\Reporting\AiTrustMetrics;
use App\Services\Reporting\BusinessMetrics;
use App\Services\Reporting\Metric;
use App\Services\Reporting\ProviderStatus;

/**
 * Phase 8 reporting. The risk here is not that a query errors — it's that a
 * number is quietly WRONG or quietly misleading, which is worse on a dashboard
 * whose purpose is deciding whether to trust the AI.
 */
function trust(): AiTrustMetrics
{
    return app(AiTrustMetrics::class);
}

function business(): BusinessMetrics
{
    return app(BusinessMetrics::class);
}

// --- Metric: a rate must carry its denominator -----------------------------

it('refuses to present a rate it cannot stand behind', function () {
    config(['reporting.min_sample' => 20]);

    // A confident-looking percentage over a tiny sample is the failure mode.
    $tiny = new Metric(numerator: 1, denominator: 5);
    expect($tiny->rate())->toBe(0.2)
        ->and($tiny->isReliable())->toBeFalse()
        ->and($tiny->display())->toBe('1/5')             // raw count, not "20.0%"
        ->and($tiny->description())->toContain('too few');

    $solid = new Metric(numerator: 5, denominator: 100);
    expect($solid->isReliable())->toBeTrue()
        ->and($solid->display())->toBe('5.0%');
});

it('does not divide by zero when there is no data', function () {
    $empty = new Metric(0, 0);

    expect($empty->rate())->toBeNull()
        ->and($empty->display())->toBe('—')
        ->and($empty->description())->toBe('No data yet');
});

// --- AI trust --------------------------------------------------------------

it('computes the receptionist fallback rate over receptionist runs only', function () {
    AiRun::factory()->count(3)->fellBackToHuman()->create();
    AiRun::factory()->count(7)->create();
    // Analysis runs must not pollute a receptionist metric.
    AiRun::factory()->count(5)->analysis()->fellBackToHuman()->create();

    $metric = trust()->fallbackRate();

    expect($metric->numerator)->toBe(3)
        ->and($metric->denominator)->toBe(10)
        ->and($metric->rate())->toBe(0.3);
});

it('ignores runs outside the reporting window', function () {
    AiRun::factory()->fellBackToHuman()->create();
    AiRun::factory()->fellBackToHuman()->create(['created_at' => now()->subDays(90)]);

    expect(trust()->fallbackRate()->denominator)->toBe(1);
});

it('measures rejection against REVIEWED actions, not the pending backlog', function () {
    AiAction::factory()->count(2)->rejected()->create();
    AiAction::factory()->count(6)->approved()->create();
    // Drafts are unjudged. Counting them would drag the rate toward zero and make
    // the AI look better the bigger the backlog gets.
    AiAction::factory()->count(50)->create(); // draft by default

    $metric = trust()->rejectionRate();

    expect($metric->numerator)->toBe(2)
        ->and($metric->denominator)->toBe(8)
        ->and($metric->rate())->toBe(0.25)
        ->and(trust()->pendingReviewCount())->toBe(50);
});

it('counts a company as disagreeing only when both analyses exist', function () {
    $agree = Company::factory()->create();
    CompanyManualAnalysis::factory()->for($agree)->create(['priority' => AnalysisPriority::High]);
    CompanyAiAnalysis::factory()->for($agree)->create(['inferred_priority' => AnalysisPriority::High]);

    $disagree = Company::factory()->create();
    CompanyManualAnalysis::factory()->for($disagree)->create(['priority' => AnalysisPriority::Low]);
    CompanyAiAnalysis::factory()->for($disagree)->create(['inferred_priority' => AnalysisPriority::High]);

    // AI-only: silence, not agreement. Must not appear in the denominator.
    $aiOnly = Company::factory()->create();
    CompanyAiAnalysis::factory()->for($aiOnly)->create(['inferred_priority' => AnalysisPriority::High]);

    $metric = trust()->disagreementRate();

    expect($metric->denominator)->toBe(2)
        ->and($metric->numerator)->toBe(1)
        ->and($metric->rate())->toBe(0.5);
});

it('reports no AI metrics at all on an empty system rather than fake zeros', function () {
    expect(trust()->fallbackRate()->rate())->toBeNull()
        ->and(trust()->rejectionRate()->rate())->toBeNull()
        ->and(trust()->disagreementRate()->rate())->toBeNull()
        ->and(trust()->averageConfidence())->toBeNull()
        ->and(trust()->medianLatencyMs())->toBeNull();
});

it('uses a median latency so one timeout cannot skew it', function () {
    foreach ([100, 200, 300, 400, 99999] as $ms) {
        AiRun::factory()->create(['latency_ms' => $ms]);
    }

    expect(trust()->medianLatencyMs())->toBe(300); // a mean would report 20,199
});

// --- Business --------------------------------------------------------------

it('groups the pipeline by status', function () {
    Company::factory()->count(3)->create(['status' => CompanyStatus::Lead]);
    Company::factory()->count(2)->create(['status' => CompanyStatus::Customer]);

    expect(business()->pipelineByStatus())->toBe(['customer' => 2, 'lead' => 3]);
});

it('separates imported companies from manual ones', function () {
    Company::factory()->count(2)->create(['source' => RecordSource::Import]);
    Company::factory()->create(['source' => RecordSource::Manual]);

    expect(business()->companiesBySource())->toMatchArray(['import' => 2, 'manual' => 1]);
});

it('counts overdue and unassigned work', function () {
    $rep = User::factory()->create();

    Task::factory()->create(['status' => TaskStatus::Open, 'due_at' => now()->subDay(), 'assigned_to' => null]);
    Task::factory()->create(['status' => TaskStatus::Open, 'due_at' => now()->addDay(), 'assigned_to' => $rep->id]);
    Task::factory()->create([
        'status' => TaskStatus::Completed,
        'due_at' => now()->subDay(),
        'completed_at' => now(),
        'assigned_to' => $rep->id,
    ]);

    expect(business()->overdueTasks())->toBe(1)   // completed-but-late is not overdue
        ->and(business()->unassignedTasks())->toBe(1);
});

// --- Compliance gauges ------------------------------------------------------

it('counts committed imports with no documented lawful basis', function () {
    // Ties to GO-LIVE-LEGAL item #2 — above zero means personal data was loaded
    // without recording why we may process it.
    Import::factory()->create(['status' => ImportStatus::Completed, 'lawful_basis' => null]);
    Import::factory()->create(['status' => ImportStatus::Completed, 'lawful_basis' => LawfulBasis::Consent]);
    // A staged-but-uncommitted import hasn't written anything yet.
    Import::factory()->create(['status' => ImportStatus::Previewed, 'lawful_basis' => null]);

    expect(business()->importsMissingLawfulBasis())->toBe(1);
});

it('counts imports whose basis needs an assessment but records none', function () {
    Import::factory()->create(['lawful_basis' => LawfulBasis::LegitimateInterest, 'lawful_basis_notes' => null]);
    Import::factory()->create(['lawful_basis' => LawfulBasis::LegitimateInterest, 'lawful_basis_notes' => 'LIA-2026-014']);
    Import::factory()->create(['lawful_basis' => LawfulBasis::Consent, 'lawful_basis_notes' => null]);

    expect(business()->importsWithUnjustifiedBasis())->toBe(1);
});

// --- Provider status --------------------------------------------------------

it('knows every provider is a fake by default', function () {
    $status = app(ProviderStatus::class);

    expect($status->hasFakes())->toBeTrue()
        ->and($status->allFake())->toBeTrue()
        ->and($status->fakes())->toContain('Receptionist LLM', 'Telephony', 'Embeddings');
});

it('stops warning about a provider once a real one is configured', function () {
    // Reads the SAME keys AppServiceProvider binds on, so the banner clears itself
    // rather than rotting into a stale string.
    config(['receptionist.drivers.llm' => 'claude']);

    $status = app(ProviderStatus::class);

    expect($status->fakes())->not->toContain('Receptionist LLM')
        ->and($status->live())->toContain('Receptionist LLM')
        ->and($status->allFake())->toBeFalse()
        ->and($status->hasFakes())->toBeTrue(); // others still faked
});
