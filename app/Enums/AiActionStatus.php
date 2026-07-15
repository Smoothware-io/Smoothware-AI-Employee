<?php

namespace App\Enums;

/**
 * Lifecycle of an AI-proposed action.
 *
 *   draft        -> proposed by the AI, awaiting human review (Phase 3+ default)
 *   approved     -> a human approved it; applied_at records when it executed
 *   rejected     -> a human rejected it; it never executes
 *   auto_applied -> executed autonomously without human review (earned autonomy),
 *                   still fully audited
 */
enum AiActionStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case AutoApplied = 'auto_applied';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft (awaiting review)',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::AutoApplied => 'Auto-applied',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Rejected;
    }
}
