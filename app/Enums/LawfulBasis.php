<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * The GDPR Art. 6(1) lawful basis under which a batch of personal data was
 * imported. Recorded per import so "where did this contact come from, and under
 * what basis?" is answerable from the data rather than from memory
 * (see GO-LIVE-LEGAL.md item #2).
 *
 * There is deliberately NO "unknown" case — a null column means nobody has
 * answered the question yet, which is honest. An "unknown" enum value would
 * look like an answer.
 */
enum LawfulBasis: string implements HasColor, HasDescription, HasLabel
{
    case Consent = 'consent';                        // Art. 6(1)(a)
    case Contract = 'contract';                      // Art. 6(1)(b)
    case LegitimateInterest = 'legitimate_interest'; // Art. 6(1)(f)
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Consent => 'Consent — Art. 6(1)(a)',
            self::Contract => 'Contract — Art. 6(1)(b)',
            self::LegitimateInterest => 'Legitimate interest — Art. 6(1)(f)',
            self::Other => 'Other (explain in notes)',
        };
    }

    public function getDescription(): ?string
    {
        return match ($this) {
            self::Consent => 'The people on this list actively opted in, and we can evidence it.',
            self::Contract => 'Necessary for a contract with the data subject, or steps taken at their request.',
            self::LegitimateInterest => 'Conditional, not automatic: must be necessary + proportionate, and normally requires a recorded LIA. Reference it in the notes.',
            self::Other => 'Anything else — state the basis and its reasoning in the notes.',
        };
    }

    /**
     * Amber for legitimate interest: it is the most-used and most-misused basis
     * for cold B2B lists, and it is the one that carries an assessment burden.
     * The colour is a nudge, not a verdict.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Consent, self::Contract => 'success',
            self::LegitimateInterest => 'warning',
            self::Other => 'gray',
        };
    }

    /** Bases that require a recorded justification before real data is imported. */
    public function requiresAssessment(): bool
    {
        return in_array($this, [self::LegitimateInterest, self::Other], true);
    }
}
