<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CallStatus: string implements HasColor, HasLabel
{
    case Completed = 'completed';
    case Missed = 'missed';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case Failed = 'failed';
    case Voicemail = 'voicemail';

    public function getLabel(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Missed => 'Missed',
            self::NoAnswer => 'No answer',
            self::Busy => 'Busy',
            self::Failed => 'Failed',
            self::Voicemail => 'Voicemail',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Voicemail => 'info',
            self::Missed, self::NoAnswer, self::Busy => 'warning',
            self::Failed => 'danger',
        };
    }
}
