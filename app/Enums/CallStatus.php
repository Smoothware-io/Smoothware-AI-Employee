<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CallStatus: string implements HasColor, HasLabel
{
    // Live states, only reachable on an outbound call we placed (Phase 6). A
    // call that is ringing or talking is not "completed" and not "failed" — it
    // has no outcome yet, and reporting must not count it as one.
    case Dialing = 'dialing';
    case InProgress = 'in_progress';

    case Completed = 'completed';
    case Missed = 'missed';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case Failed = 'failed';
    case Voicemail = 'voicemail';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dialing => 'Dialing…',
            self::InProgress => 'In progress',
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
            self::Dialing, self::InProgress => 'info',
            self::Completed => 'success',
            self::Voicemail => 'info',
            self::Missed, self::NoAnswer, self::Busy => 'warning',
            self::Failed => 'danger',
        };
    }
}
