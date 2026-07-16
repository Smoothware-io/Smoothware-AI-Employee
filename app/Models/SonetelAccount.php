<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use App\Services\Outbound\SonetelTokenService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rep's connected Sonetel account. Created and refreshed only via
 * {@see SonetelTokenService} — never by mass assignment from a form, so the
 * password can never accidentally land in a column.
 *
 * @property CarbonInterface|null $expires_at
 */
class SonetelAccount extends Model
{
    use HasFactory, LogsEvents;

    protected $fillable = [
        'user_id',
        'username',
        'sonetel_number',
        'access_token',
        'refresh_token',
        'expires_at',
        'connected_at',
        'last_refreshed_at',
    ];

    /**
     * Tokens are bearer credentials: anyone holding one can place calls billed to
     * this account. They never appear in the append-only log, and `$hidden` keeps
     * them out of any accidental serialisation too.
     *
     * @var array<int, string>
     */
    protected $hidden = ['access_token', 'refresh_token'];

    /** @var array<int, string> */
    protected array $auditRedacted = ['access_token', 'refresh_token', 'username'];

    protected function casts(): array
    {
        return [
            // Encrypted at rest — a database leak must not hand over the ability
            // to make phone calls on someone else's account.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    /**
     * Usable right now? A token expiring in 30 seconds is not usable — the call
     * would start and the API would reject it mid-flight — so a safety margin is
     * treated as expired.
     */
    public function hasFreshToken(int $marginSeconds = 300): bool
    {
        return filled($this->access_token)
            && $this->expires_at !== null
            && $this->expires_at->gt(now()->addSeconds($marginSeconds));
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /** Can we get a new token without asking the rep to log in again? */
    public function canRefresh(): bool
    {
        return filled($this->refresh_token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
