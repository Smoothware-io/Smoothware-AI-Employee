<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ImportStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';       // uploaded, not yet staged
    case Previewed = 'previewed';   // parsed + deduped; awaiting commit
    case Completed = 'completed';   // committed
    case Failed = 'failed';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Previewed => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
