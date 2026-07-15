<?php

namespace App\Enums;

enum CallStatus: string
{
    case Completed = 'completed';
    case Missed = 'missed';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case Failed = 'failed';
    case Voicemail = 'voicemail';

    public function label(): string
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

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Voicemail => 'info',
            self::Missed, self::NoAnswer, self::Busy => 'warning',
            self::Failed => 'danger',
        };
    }
}
