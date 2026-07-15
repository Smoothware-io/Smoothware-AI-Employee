<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of an AI-proposed action.
 *
 *   draft        -> proposed by the AI, awaiting human review (Phase 3+ default)
 *   approved     -> a human approved it; applied_at records when it executed
 *   rejected     -> a human rejected it; it never executes
 *   auto_applied -> executed autonomously without human review (earned autonomy),
 *                   still fully audited
 */
enum AiActionStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case AutoApplied = 'auto_applied';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft (awaiting review)',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::AutoApplied => 'Auto-applied',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::AutoApplied => 'info',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Rejected;
    }
}
