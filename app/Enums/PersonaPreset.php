<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The kind of job the AI is doing on a call.
 *
 * This is NOT the instruction text — it is the brief a human picks, which the
 * generator then turns into instructions grounded in the actual knowledge base.
 * Writing a good system prompt is a skill; choosing "I want a sales call that
 * books meetings" is not, and the second is what a salesperson can actually do.
 */
enum PersonaPreset: string implements HasLabel
{
    case Sales = 'sales';
    case Support = 'support';
    case Reception = 'reception';
    case Qualification = 'qualification';
    case FollowUp = 'follow_up';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sales => 'Sales — introduce us and book a meeting',
            self::Support => 'Support — help an existing customer',
            self::Reception => 'Reception — answer, understand, route',
            self::Qualification => 'Qualification — find out if they are a fit',
            self::FollowUp => 'Follow-up — pick up an earlier conversation',
        };
    }

    /** What a good call of this kind achieves, in one line. */
    public function goal(): string
    {
        return match ($this) {
            self::Sales => 'Understand what they need and book a free 30-minute intro meeting.',
            self::Support => 'Understand the problem, answer it from the knowledge base, and escalate to a human if it is not covered.',
            self::Reception => 'Find out why they are calling, answer what you can, and note anything the team must follow up.',
            self::Qualification => 'Find out whether this company is a realistic fit, and note what you learn.',
            self::FollowUp => 'Reconnect on the earlier conversation and agree a concrete next step.',
        };
    }

    /** The brief handed to the generator. */
    public function brief(): string
    {
        return match ($this) {
            self::Sales => 'a salesperson making a first outbound contact — warm, brief, respectful of their time, never pushy',
            self::Support => 'a support agent helping an existing customer — patient, precise, happy to say "I will pass this to a colleague"',
            self::Reception => 'a receptionist answering the company phone — welcoming, efficient, good at working out what someone actually needs',
            self::Qualification => 'a sales development rep working out whether a company is a fit — curious, asks good questions, does not pitch',
            self::FollowUp => 'someone picking up a conversation that already started — familiar but not presumptuous',
        };
    }
}
