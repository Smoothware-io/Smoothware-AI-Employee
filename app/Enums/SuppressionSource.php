<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How we learned that someone does not want to be contacted.
 *
 * Recorded because the provenance of an objection matters: "they said so on a
 * call" and "they appear in the national opt-out register" are different facts
 * with different evidence, and a regulator asking "why did you stop calling
 * them?" — or worse, "why didn't you?" — wants the difference.
 */
enum SuppressionSource: string implements HasColor, HasLabel
{
    /** A human typed it in — the rep was told during a conversation. */
    case Manual = 'manual';

    /** Said on a call. */
    case Call = 'call';

    /** Replied to an email / clicked unsubscribe. */
    case Email = 'email';

    /** Screened from an official opt-out register (Phase 6, not yet built). */
    case Register = 'register';

    /** Came in on an imported list already marked do-not-contact. */
    case Import = 'import';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Entered by hand',
            self::Call => 'Told us on a call',
            self::Email => 'Email / unsubscribe',
            self::Register => 'Opt-out register',
            self::Import => 'Marked in an imported list',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Register => 'danger',   // legally loaded — screened, not volunteered
            self::Call, self::Email => 'warning',
            default => 'gray',
        };
    }
}
