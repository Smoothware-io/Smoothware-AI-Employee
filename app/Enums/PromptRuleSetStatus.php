<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a versioned prompt ruleset. Exactly one set is `Active` at a
 * time; that version is what AI calls (Phase 3+) record as their governing
 * ruleset. Editing a published set means publishing a new version.
 */
enum PromptRuleSetStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Archived => 'warning',
        };
    }
}
