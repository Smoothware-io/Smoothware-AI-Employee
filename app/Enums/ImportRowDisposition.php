<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What the preview decided for a staged CSV row — shown before committing so the
 * rep sees exactly what will be created vs. matched vs. skipped.
 */
enum ImportRowDisposition: string implements HasColor, HasLabel
{
    case Create = 'create';        // new company
    case Match = 'match';          // deduped onto an existing company
    case Skip = 'skip';            // empty row
    case Invalid = 'invalid';      // failed validation (e.g. no name)
    // On the do-not-contact list. Its own disposition rather than a Skip: being
    // told "never contact me again" is a decision someone made about us, and it
    // should be visible in the preview, not lumped in with blank lines.
    case Suppressed = 'suppressed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Suppressed => 'Suppressed — do not contact',
            default => ucfirst($this->value),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Create => 'success',
            self::Match => 'info',
            self::Skip => 'gray',
            self::Invalid => 'danger',
            self::Suppressed => 'danger',
        };
    }
}
