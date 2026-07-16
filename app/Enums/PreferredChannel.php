<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How a contact prefers to be reached.
 *
 * Lives on Contact rather than Company on purpose: a company doesn't have a
 * preference — its people do. "How do we reach this company" is already answered
 * by `companies.email` / `companies.phone`. A company-level channel would also be
 * a fiction whenever two contacts disagree (Eva prefers email, Jan prefers phone).
 *
 * There is deliberately NO "unknown" case: null means nobody has recorded a
 * preference, which is honest. An "unknown" value would look like an answer, and
 * — more importantly — would let automation treat "we never asked" as if the
 * person had told us something. Same reasoning as {@see LawfulBasis}.
 *
 * Consumers (Phase 7 follow-up rules; any future Phase 6 outbound) should read
 * this as a STATED PREFERENCE, not a permission: it says how someone would rather
 * be contacted, never whether we are allowed to contact them at all. That
 * question is lawful basis + the right to object — see GO-LIVE-LEGAL.md.
 */
enum PreferredChannel: string implements HasColor, HasIcon, HasLabel
{
    case Phone = 'phone';
    case Email = 'email';
    case Either = 'either';

    public function getLabel(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Email => 'Email',
            self::Either => 'Either is fine',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Phone => 'info',
            self::Email => 'success',
            self::Either => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Phone => 'heroicon-o-phone',
            self::Email => 'heroicon-o-envelope',
            self::Either => 'heroicon-o-chat-bubble-left-right',
        };
    }

    /** Does this preference permit reaching out by phone? */
    public function allowsPhone(): bool
    {
        return $this !== self::Email;
    }

    /** Does this preference permit reaching out by email? */
    public function allowsEmail(): bool
    {
        return $this !== self::Phone;
    }

    /**
     * Normalise a free-text value from an imported list ("e-mail", "CALL",
     * "both"). Returns null for anything unrecognised — an imported list is
     * someone else's data entry, and guessing at "carrier pigeon" would invent a
     * preference the person never stated.
     */
    public static function fromImport(?string $value): ?self
    {
        $normalised = (string) preg_replace('/[^a-z]/', '', mb_strtolower((string) $value));

        return match ($normalised) {
            'phone', 'tel', 'telephone', 'call', 'calling', 'voice', 'mobile' => self::Phone,
            'email', 'mail', 'eml' => self::Email,
            'either', 'any', 'both', 'nopreference', 'nopref' => self::Either,
            default => null,
        };
    }
}
