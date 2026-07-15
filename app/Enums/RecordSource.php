<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Provenance of a user-data record — the backbone of "human vs. AI data is
 * visually and structurally distinct" (product principle #2). Drives the AI/
 * human badge in the UI. See App\Concerns\HasProvenance.
 */
enum RecordSource: string implements HasColor, HasLabel
{
    case Manual = 'manual';      // a human created it
    case Import = 'import';      // CSV import (Phase 5)
    case Ai = 'ai';              // an AI action created it (Phase 3+)
    case System = 'system';      // created by the system (jobs, seeders)

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Import => 'Imported',
            self::Ai => 'AI',
            self::System => 'System',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Import => 'info',
            self::Ai => 'warning',
            self::System => 'gray',
        };
    }

    public function isAi(): bool
    {
        return $this === self::Ai;
    }
}
