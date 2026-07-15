<?php

namespace App\Enums;

enum NoteCategory: string
{
    case Internal = 'internal';
    case FollowUp = 'follow_up';
    case Meeting = 'meeting';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::FollowUp => 'Follow-up',
            self::Meeting => 'Meeting',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Internal => 'gray',
            self::FollowUp => 'warning',
            self::Meeting => 'info',
        };
    }
}
