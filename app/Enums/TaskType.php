<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaskType: string implements HasLabel
{
    case CallBack = 'call_back';
    case SendProposal = 'send_proposal';
    case SendEmail = 'send_email';
    case FollowUp = 'follow_up';

    public function getLabel(): string
    {
        return match ($this) {
            self::CallBack => 'Call back',
            self::SendProposal => 'Send proposal',
            self::SendEmail => 'Send email',
            self::FollowUp => 'Follow-up',
        };
    }
}
