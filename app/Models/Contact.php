<?php

namespace App\Models;

use App\Concerns\HasProvenance;
use App\Concerns\LogsEvents;
use App\Enums\PreferredChannel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person at a company. Entirely personal data, so every human-identifying
 * field is kept out of the append-only audit log.
 *
 * @property bool $is_decision_maker
 * @property PreferredChannel|null $preferred_channel
 */
class Contact extends Model
{
    use HasFactory, HasProvenance, LogsEvents, SoftDeletes;

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'company_id',
        'first_name',
        'last_name',
        'job_title',
        'is_decision_maker',
        'email',
        'phone',
        'preferred_channel',
        'source',
        'ai_action_id',
        'created_by',
    ];

    /**
     * Identifying values stay out of the append-only log. `preferred_channel` is
     * deliberately NOT redacted: it is a low-risk categorical, the event already
     * names the contact by id, and the changed VALUE is the whole point of
     * auditing a preference ("who set this to email, and when?").
     *
     * @var array<int, string>
     */
    protected array $auditRedacted = ['first_name', 'last_name', 'email', 'phone'];

    protected function casts(): array
    {
        return [
            'is_decision_maker' => 'boolean',
            'preferred_channel' => PreferredChannel::class,
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
