<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What a suppression entry identifies — the do-not-contact list (GO-LIVE-LEGAL #2,
 * the right to object).
 *
 * Deliberately NOT "person": we suppress a REACHABLE ADDRESS, not an identity.
 * A person can be reached at several numbers and leave a company; an objection
 * attaches to the thing we would dial or send to. Suppressing a domain covers
 * "never contact anyone at this company again".
 */
enum SuppressionType: string implements HasDescription, HasIcon, HasLabel
{
    case Phone = 'phone';
    case Email = 'email';
    case Domain = 'domain';

    public function getLabel(): string
    {
        return match ($this) {
            self::Phone => 'Phone number',
            self::Email => 'Email address',
            self::Domain => 'Whole company (domain)',
        };
    }

    public function getDescription(): ?string
    {
        return match ($this) {
            self::Phone => 'Never call this number again.',
            self::Email => 'Never email this address again.',
            self::Domain => 'Never contact anyone at this company again — covers every contact under the domain.',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Phone => 'heroicon-o-phone-x-mark',
            self::Email => 'heroicon-o-envelope',
            self::Domain => 'heroicon-o-building-office-2',
        };
    }
}
