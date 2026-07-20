<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rep's Google Calendar connection.
 *
 * Tokens are ENCRYPTED at rest. A refresh token is standing read/write access to
 * a person's calendar until they revoke it, which most people never do — a
 * database dump must not hand that out.
 */
class GoogleCalendarAccount extends Model
{
    use HasFactory, LogsEvents;

    protected $fillable = [
        'user_id', 'google_email', 'access_token', 'refresh_token', 'expires_at',
        'calendar_id', 'block_from_busy', 'push_appointments', 'last_synced_at', 'last_error',
    ];

    /** Never let a token reach the audit log or a JSON response. */
    protected $hidden = ['access_token', 'refresh_token'];

    protected array $auditRedacted = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'block_from_busy' => 'boolean',
            'push_appointments' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Is the access token still usable?
     *
     * A minute of headroom: a token that expires mid-request is the same as an
     * expired one, and this is checked while a caller is on the line.
     */
    public function hasFreshToken(): bool
    {
        return filled($this->access_token)
            && $this->expires_at !== null
            && $this->expires_at->isAfter(now()->addMinute());
    }
}
