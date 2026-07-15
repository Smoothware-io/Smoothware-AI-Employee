<?php

namespace App\Services\Analysis;

use App\Models\CompanyAiAnalysis;
use App\Models\CompanyManualAnalysis;

/**
 * Surfaces meaningful disagreements between the rep's manual analysis and the
 * AI's (product requirement: don't silently show both side-by-side). This is
 * where the rep's judgment visibly contextualises or overrides the AI.
 *
 * Phase 4 compares the priority read; the shape supports adding more dimensions.
 */
class DisagreementDetector
{
    /**
     * @return array<int, array{field: string, manual: string, ai: string, detail: string}>
     */
    public function detect(?CompanyManualAnalysis $manual, ?CompanyAiAnalysis $ai): array
    {
        if ($manual === null || $ai === null) {
            return [];
        }

        $disagreements = [];

        if ($manual->priority !== null
            && $ai->inferred_priority !== null
            && $manual->priority !== $ai->inferred_priority) {
            $disagreements[] = [
                'field' => 'Priority',
                'manual' => $manual->priority->getLabel(),
                'ai' => $ai->inferred_priority->getLabel(),
                'detail' => "You set priority {$manual->priority->getLabel()}; the AI inferred {$ai->inferred_priority->getLabel()} from the website signals. Your call takes precedence.",
            ];
        }

        return $disagreements;
    }

    public function hasDisagreement(?CompanyManualAnalysis $manual, ?CompanyAiAnalysis $ai): bool
    {
        return $this->detect($manual, $ai) !== [];
    }
}
