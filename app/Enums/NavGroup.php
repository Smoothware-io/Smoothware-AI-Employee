<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The sidebar, in the words of the people who use it.
 *
 * Grouping used to be a string typed into each resource, which drifted into six
 * half-groups ("AI Receptionist", "Automation", "Import", "Knowledge Base",
 * "CRM") with a third of the resources in none of them. One enum means the
 * sidebar has one definition, and adding a resource forces a deliberate choice
 * about where it belongs.
 *
 * The names are deliberately NOT our vocabulary. "Prompt rules", "AI runs" and
 * "Suppressions" describe how the system is built; a salesperson opening this at
 * 9am is asking "who do I call", "what did the AI do", "how do I teach it". The
 * groups answer those questions instead.
 *
 * No icons here on purpose: Filament refuses to render a group icon when its
 * items also have icons, and per-resource icons are the more useful of the two.
 */
enum NavGroup: string implements HasLabel
{
    case Work = 'work';
    case Leads = 'leads';
    case TeachTheAi = 'teach';
    case AiActivity = 'ai_activity';
    case Settings = 'settings';

    public function getLabel(): string
    {
        return match ($this) {
            self::Work => 'Daily work',
            self::Leads => 'Leads & imports',
            self::TeachTheAi => 'Teach the AI',
            self::AiActivity => 'What the AI did',
            self::Settings => 'Settings',
        };
    }

    /** Sidebar order. Daily work first, because that is where people live. */
    public function order(): int
    {
        return match ($this) {
            self::Work => 1,
            self::Leads => 2,
            self::AiActivity => 3,
            self::TeachTheAi => 4,
            self::Settings => 5,
        };
    }
}
