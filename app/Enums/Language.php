<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * What language to speak to a company in.
 *
 * On Company rather than Contact, unlike {@see PreferredChannel}: on an outbound
 * call we dial a business and talk to whoever answers, so we cannot know the
 * person in advance. The company's working language is the best guess available.
 *
 * Nullable everywhere. Null means "nobody told us", and {@see fromCountry()} is
 * a FALLBACK for that case, not a fact — inferring Dutch from a .nl number is a
 * reasonable default, not knowledge. If someone records a real preference, it
 * wins.
 */
enum Language: string implements HasDescription, HasLabel
{
    case Dutch = 'nl';
    case English = 'en';
    case German = 'de';
    case French = 'fr';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dutch => 'Nederlands',
            self::English => 'English',
            self::German => 'Deutsch',
            self::French => 'Français',
        };
    }

    public function getDescription(): ?string
    {
        return match ($this) {
            self::Dutch => 'Default for NL/BE companies.',
            self::English => 'Use when they wrote to us in English.',
            self::German => 'DE/AT/CH.',
            self::French => 'FR, and Wallonia.',
        };
    }

    /** How the AI should be told to speak, in its own instructions. */
    public function instruction(): string
    {
        return match ($this) {
            self::Dutch => 'Spreek Nederlands.',
            self::English => 'Speak English.',
            self::German => 'Sprich Deutsch.',
            self::French => 'Parle français.',
        };
    }

    /**
     * A guess from the country, for when nobody recorded a language.
     *
     * Deliberately conservative: BE is bilingual and CH trilingual, so we return
     * null rather than pick wrong. Being asked "spreekt u Nederlands?" is a small
     * cost; being confidently addressed in the wrong language is worse.
     */
    public static function fromCountry(?string $country): ?self
    {
        $code = mb_strtoupper(trim((string) $country));

        return match ($code) {
            'NL', 'NLD', 'NETHERLANDS', 'NEDERLAND' => self::Dutch,
            'DE', 'DEU', 'GERMANY', 'DUITSLAND' => self::German,
            'FR', 'FRA', 'FRANCE', 'FRANKRIJK' => self::French,
            'GB', 'UK', 'IE', 'US', 'USA' => self::English,
            default => null, // includes BE and CH: ask, don't assume
        };
    }

    /** Normalise whatever an imported sheet put in a language column. */
    public static function fromImport(?string $value): ?self
    {
        $normalised = mb_strtolower(trim((string) $value));

        return match ($normalised) {
            'nl', 'nld', 'dutch', 'nederlands', 'holland' => self::Dutch,
            'en', 'eng', 'english', 'engels' => self::English,
            'de', 'deu', 'german', 'duits', 'deutsch' => self::German,
            'fr', 'fra', 'french', 'frans', 'français', 'francais' => self::French,
            default => null,
        };
    }
}
