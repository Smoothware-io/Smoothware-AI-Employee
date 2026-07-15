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
    case Create = 'create';   // new company
    case Match = 'match';      // deduped onto an existing company
    case Skip = 'skip';        // empty row
    case Invalid = 'invalid';  // failed validation (e.g. no name)

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Create => 'success',
            self::Match => 'info',
            self::Skip => 'gray',
            self::Invalid => 'danger',
        };
    }
}
