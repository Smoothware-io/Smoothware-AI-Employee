<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NoteCategory: string implements HasColor, HasLabel
{
    case Internal = 'internal';
    case FollowUp = 'follow_up';
    case Meeting = 'meeting';

    public function getLabel(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::FollowUp => 'Follow-up',
            self::Meeting => 'Meeting',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Internal => 'gray',
            self::FollowUp => 'warning',
            self::Meeting => 'info',
        };
    }
}
