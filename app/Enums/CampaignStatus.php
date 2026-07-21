<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CampaignStatus: string implements HasColor, HasLabel
{
    /** Being prepared. Nothing dials. This is the default, on purpose. */
    case Draft = 'draft';

    case Running = 'running';

    /** Stopped by a human. Resumable, and keeps its progress. */
    case Paused = 'paused';

    /** Nobody left to call. */
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft — not calling',
            self::Running => 'Calling',
            self::Paused => 'Paused',
            self::Completed => 'Finished',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Running => 'success',
            self::Paused => 'warning',
            self::Completed => 'info',
        };
    }

    public function isDialling(): bool
    {
        return $this === self::Running;
    }
}
