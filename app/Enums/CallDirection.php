<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CallDirection: string implements HasColor, HasLabel
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inbound => 'Inbound',
            self::Outbound => 'Outbound',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Inbound => 'info',
            self::Outbound => 'gray',
        };
    }
}
