<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use App\Enums\CallDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The AI's role and goal for one call direction, editable by a human.
 *
 * Falls back to a built-in default when no row exists, so the system is never in
 * a state where the AI has no idea who it is — an empty table must not produce a
 * roleless AI on a live call.
 *
 * @property CallDirection $direction
 */
class CallPersona extends Model
{
    use HasFactory, LogsEvents;

    protected $fillable = ['direction', 'role', 'goal', 'updated_by'];

    protected function casts(): array
    {
        return ['direction' => CallDirection::class];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The persona for a direction, or the built-in default.
     *
     * The defaults are the exact strings that were hardcoded in
     * CallInstructionBuilder before this table existed, so adopting the feature
     * changes nothing about how the AI behaves until a human edits it.
     */
    public static function forDirection(CallDirection $direction): self
    {
        return static::query()->firstWhere('direction', $direction->value)
            ?? new self([
                'direction' => $direction->value,
                'role' => static::defaultRole($direction),
            ]);
    }

    public static function defaultRole(CallDirection $direction): string
    {
        return $direction === CallDirection::Inbound
            ? 'Je NEEMT DE TELEFOON OP namens Smoothware, een Nederlands web- en '
                .'softwarebureau. Deze persoon belt ONS — jij belt hen niet. Vraag waarmee je kunt '
                .'helpen, luister, en beantwoord alleen wat je uit de kennisbank weet.'
            : 'Je BELT namens Smoothware, een Nederlands web- en softwarebureau. '
                .'Jij hebt hen gebeld — respecteer hun tijd.';
    }
}
