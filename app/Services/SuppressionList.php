<?php

namespace App\Services;

use App\Enums\SuppressionSource;
use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Suppression;
use Illuminate\Support\Facades\Auth;

/**
 * The do-not-contact list: "never contact me again", made enforceable.
 *
 * NORMALISATION IS THE WHOLE GAME. `+31 6 12345678`, `0612345678` and
 * `0031612345678` are one number and three strings. If matching misses, we call
 * someone who told us not to — and nothing errors, nothing fails a test, we just
 * quietly commit the violation. So the normaliser is the most safety-critical
 * function in this codebase, and it is tested harder than anything else here.
 *
 * The rule everywhere below: **when in doubt, suppress**. A false positive costs
 * one un-made call. A false negative costs a regulator's attention and a person's
 * trust. Those are not symmetric, so the code is not symmetric either.
 */
class SuppressionList
{
    /**
     * Is any of these addresses suppressed?
     *
     * Domain is checked too, so "never contact anyone at this company" covers a
     * contact whose own number was never listed.
     */
    public function isSuppressed(?string $phone = null, ?string $email = null, ?string $domain = null): bool
    {
        return $this->firstMatch($phone, $email, $domain) !== null;
    }

    /** The specific entry that blocks contact, so callers can say WHY. */
    public function firstMatch(?string $phone = null, ?string $email = null, ?string $domain = null): ?Suppression
    {
        $candidates = array_filter([
            [SuppressionType::Phone, $phone],
            [SuppressionType::Email, $email],
            [SuppressionType::Domain, $domain],
            // An email implies its domain: suppressing acme.nl must also stop
            // eva@acme.nl, even if only the address was given to us.
            [SuppressionType::Domain, $this->domainOfEmail($email)],
        ], fn (array $pair): bool => filled($pair[1]));

        foreach ($candidates as [$type, $value]) {
            $normalized = $this->normalize($type, (string) $value);

            if ($normalized === null) {
                continue;
            }

            $match = Suppression::query()
                ->active()
                ->where('type', $type->value)
                ->where('value_normalized', $normalized)
                ->first();

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /** Everything we know about a contact, checked at once. */
    public function isContactSuppressed(Contact $contact): bool
    {
        return $this->isSuppressed(
            phone: $contact->phone,
            email: $contact->email,
            domain: $contact->company?->domain,
        );
    }

    public function isCompanySuppressed(Company $company): bool
    {
        return $this->isSuppressed(
            phone: $company->phone,
            email: $company->email,
            domain: $company->domain,
        );
    }

    /**
     * Record an objection. Idempotent: re-suppressing an already-suppressed
     * address returns the existing entry rather than racing the unique index —
     * a rep told twice must never see an error for doing the right thing.
     */
    public function suppress(
        SuppressionType $type,
        string $value,
        SuppressionSource $source = SuppressionSource::Manual,
        ?string $reason = null,
        ?int $userId = null,
    ): ?Suppression {
        $normalized = $this->normalize($type, $value);

        if ($normalized === null) {
            return null; // unparseable — nothing we could ever match against
        }

        $existing = Suppression::query()
            ->active()
            ->where('type', $type->value)
            ->where('value_normalized', $normalized)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Suppression::create([
            'type' => $type,
            'value_normalized' => $normalized,
            'value_raw' => $value,
            'source' => $source,
            'reason' => $reason,
            'suppressed_at' => now(),
            'created_by' => $userId ?? Auth::id(),
        ]);
    }

    /**
     * Normalise an address to its matchable form. Returns null when the input
     * could never be matched (empty, or a phone with no digits).
     */
    public function normalize(SuppressionType $type, string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return match ($type) {
            SuppressionType::Phone => $this->normalizePhone($value),
            SuppressionType::Email => $this->normalizeEmail($value),
            SuppressionType::Domain => $this->normalizeDomain($value),
        };
    }

    /**
     * Phone → digits only, in international form without the +.
     *
     * Handles the Dutch shapes we actually see: `+31 6 1234 5678`, `0031 6…`,
     * `06-1234 5678`, `(06) 12345678`. A leading national 0 is replaced by the
     * configured country code, because `0612345678` and `+31612345678` are the
     * same phone and a rep will type either.
     *
     * LIMITATION, stated plainly: this is not libphonenumber. It is correct for
     * NL + explicit international numbers, which is this project's market. The
     * moment a second country is dialled, swap in giggsey/libphonenumber-for-php
     * — guessing at national dialling rules per country is exactly how a
     * suppression silently misses.
     */
    private function normalizePhone(string $value): ?string
    {
        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        $countryCode = (string) config('suppression.default_country_code', '31');

        // 0031… → 31…
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (! $hasPlus && str_starts_with($digits, '0')) {
            // 06… / 020… → national. The leading 0 is a TRUNK PREFIX, dropped
            // when dialling internationally, so it is replaced rather than kept.
            $digits = $countryCode.ltrim($digits, '0');
        } elseif (! $hasPlus && ! str_starts_with($digits, $countryCode)) {
            // A bare national number with no trunk 0 at all.
            $digits = $countryCode.$digits;
        }

        // "+31 (0)6 12345678" is everywhere in the Netherlands: the (0) shows the
        // trunk prefix you DROP when calling from abroad. Strip non-digits naively
        // and it becomes 3106… — a different number from 316…, which silently
        // never matches. This is the bug that calls someone who told us to stop.
        //
        // NL-SPECIFIC: no Dutch subscriber number starts with 0 after the country
        // code. In some countries (Italy) that 0 IS part of the number, so this
        // rule cannot be generalised — one more reason a second country means
        // libphonenumber, not a bigger regex.
        if (str_starts_with($digits, $countryCode.'0')) {
            $digits = $countryCode.ltrim(substr($digits, strlen($countryCode)), '0');
        }

        return $digits === '' || $digits === $countryCode ? null : $digits;
    }

    /**
     * Email → lowercased and trimmed. Deliberately NOT clever: no stripping of
     * gmail dots or +tags. Those rules are provider-specific, and treating
     * `eva+crm@acme.nl` as `eva@acme.nl` would suppress an address the person
     * never gave us — over-matching in a way we could not explain.
     */
    private function normalizeEmail(string $value): ?string
    {
        $value = mb_strtolower(trim($value));

        return str_contains($value, '@') ? $value : null;
    }

    /** Domain → lowercase host, no scheme, no www., no path or port. */
    private function normalizeDomain(string $value): ?string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('#^[a-z]+://#', '', $value);
        $value = explode('/', $value)[0];
        $value = explode(':', $value)[0];
        $value = preg_replace('/^www\./', '', $value);

        return $value === '' ? null : $value;
    }

    private function domainOfEmail(?string $email): ?string
    {
        if (! filled($email) || ! str_contains((string) $email, '@')) {
            return null;
        }

        return explode('@', (string) $email)[1] ?: null;
    }
}
