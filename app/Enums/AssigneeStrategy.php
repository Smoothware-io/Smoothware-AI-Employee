<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Who a rule-created follow-up task lands on (Phase 7).
 *
 * Deliberately a strategy rather than a plain user FK: "the company's owner" has
 * to be resolved when the rule FIRES, not when it was written — owners change,
 * and a rule written six months ago should still route to whoever owns the
 * company today.
 */
enum AssigneeStrategy: string implements HasDescription, HasLabel
{
    case CompanyOwner = 'company_owner';
    case RuleCreator = 'rule_creator';
    case SpecificUser = 'specific_user';
    case Unassigned = 'unassigned';

    public function getLabel(): string
    {
        return match ($this) {
            self::CompanyOwner => "The company's owner",
            self::RuleCreator => 'Whoever wrote the rule',
            self::SpecificUser => 'A specific person',
            self::Unassigned => 'Nobody (unassigned queue)',
        };
    }

    public function getDescription(): ?string
    {
        return match ($this) {
            self::CompanyOwner => 'Resolved when the rule fires, so it follows ownership changes. Falls back to unassigned if the company has no owner.',
            self::RuleCreator => 'The person who authored the rule takes the work.',
            self::SpecificUser => 'Always the same person, regardless of who owns the company.',
            self::Unassigned => 'Lands in the unassigned queue for someone to pick up.',
        };
    }
}
