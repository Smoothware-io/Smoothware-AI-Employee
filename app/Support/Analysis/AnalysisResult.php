<?php

namespace App\Support\Analysis;

use App\Enums\AnalysisPriority;

/**
 * The AI's contribution to a company analysis (Phase 4): marketing assessment,
 * recommendations (grounded in our KB), and the AI's own priority read (which
 * the disagreement detector compares against the rep's manual priority). The
 * technical section comes from {@see WebsiteSignals}, not here.
 */
class AnalysisResult
{
    /**
     * @param  array<int, array{key: string, label: string, assessment: string, confidence: float}>  $marketing
     * @param  array<int, array{key: string, label: string, assessment: string, confidence: float}>  $recommendations
     */
    public function __construct(
        public readonly array $marketing,
        public readonly array $recommendations,
        public readonly ?AnalysisPriority $inferredPriority,
        public readonly float $overallConfidence,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}
}
