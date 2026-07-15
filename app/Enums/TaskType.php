<?php

namespace App\Enums;

enum TaskType: string
{
    case CallBack = 'call_back';
    case SendProposal = 'send_proposal';
    case SendEmail = 'send_email';
    case FollowUp = 'follow_up';

    public function label(): string
    {
        return match ($this) {
            self::CallBack => 'Call back',
            self::SendProposal => 'Send proposal',
            self::SendEmail => 'Send email',
            self::FollowUp => 'Follow-up',
        };
    }
}
