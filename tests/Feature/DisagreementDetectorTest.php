<?php

use App\Enums\AnalysisPriority;
use App\Models\CompanyAiAnalysis;
use App\Models\CompanyManualAnalysis;
use App\Services\Analysis\DisagreementDetector;

it('flags a priority disagreement between the rep and the AI', function () {
    $manual = CompanyManualAnalysis::factory()->make(['priority' => AnalysisPriority::High]);
    $ai = CompanyAiAnalysis::factory()->make(['inferred_priority' => AnalysisPriority::Low]);

    $disagreements = app(DisagreementDetector::class)->detect($manual, $ai);

    expect($disagreements)->toHaveCount(1)
        ->and($disagreements[0]['field'])->toBe('Priority')
        ->and($disagreements[0]['manual'])->toBe('High')
        ->and($disagreements[0]['ai'])->toBe('Low');
});

it('reports no disagreement when priorities align', function () {
    $manual = CompanyManualAnalysis::factory()->make(['priority' => AnalysisPriority::Medium]);
    $ai = CompanyAiAnalysis::factory()->make(['inferred_priority' => AnalysisPriority::Medium]);

    expect(app(DisagreementDetector::class)->hasDisagreement($manual, $ai))->toBeFalse();
});

it('reports no disagreement when either analysis is missing', function () {
    $ai = CompanyAiAnalysis::factory()->make(['inferred_priority' => AnalysisPriority::High]);

    expect(app(DisagreementDetector::class)->detect(null, $ai))->toBe([]);
});
