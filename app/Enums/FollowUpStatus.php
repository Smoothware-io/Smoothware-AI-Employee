<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The lifecycle of a single follow-up decision (Phase 7).
 *
 * A follow-up row is the record that a rule DECIDED something — including
 * deciding not to act. Skipped/Failed rows are kept rather than deleted, so
 * "why didn't the automation fire?" is answerable.
 */
enum FollowUpStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';      // AI-suggested, awaiting approval (deferred — see ARCHITECTURE)
    case Applied = 'applied';      // the task was created
    case Skipped = 'skipped';      // conditions no longer met, or the per-company cap was hit
    case Cancelled = 'cancelled';  // a human called it off
    case Failed = 'failed';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Applied => 'success',
            self::Skipped => 'gray',
            self::Cancelled => 'gray',
            self::Failed => 'danger',
        };
    }
}
