<?php

namespace App\Models;

use App\Concerns\HasProvenance;
use App\Concerns\LogsEvents;
use App\Enums\CallDirection;
use App\Enums\CallIntent;
use App\Enums\CallStatus;
use App\Services\CallContentEraser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A phone call. Phase 1 stores metadata only. The recording/transcript columns
 * are dormant until Phase 3; when populated they carry heavy personal data, so:
 *   - transcript/summary are ENCRYPTED at rest (casts below),
 *   - phone numbers + transcript + recording pointer never reach the audit log,
 *   - content is destroyed via {@see CallContentEraser} on
 *     retention expiry or a GDPR request, leaving metadata intact.
 *
 * @property CallDirection $direction
 * @property CallStatus $status
 * @property CallIntent|null $intent
 */
class Call extends Model
{
    use HasFactory, HasProvenance, LogsEvents, SoftDeletes;

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'company_id',
        'contact_id',
        'direction',
        'status',
        'intent',
        'from_number',
        'to_number',
        'started_at',
        'ended_at',
        'duration_seconds',
        'handled_by',
        'summary',
        'external_provider',
        'external_id',
        'recording_disk',
        'recording_path',
        'recording_bytes',
        'transcript',
        'transcript_status',
        'consent_obtained',
        'consent_method',
        'disclosed_at',
        'retention_expires_at',
        'content_erased_at',
        'erased_by',
        'source',
        'ai_action_id',
        'created_by',
    ];

    /**
     * Personal-data fields kept out of the append-only audit log.
     *
     * @var array<int, string>
     */
    protected array $auditRedacted = ['from_number', 'to_number', 'transcript', 'summary', 'recording_path'];

    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'status' => CallStatus::class,
            'intent' => CallIntent::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            // Encrypted at rest — a DB dump never leaks call content.
            'transcript' => 'encrypted',
            'summary' => 'encrypted',
            'consent_obtained' => 'boolean',
            'disclosed_at' => 'datetime',
            'retention_expires_at' => 'datetime',
            'content_erased_at' => 'datetime',
        ];
    }

    public function hasRecording(): bool
    {
        return $this->recording_path !== null;
    }

    public function isContentErased(): bool
    {
        return $this->content_erased_at !== null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** AI analysis runs performed on this call (Phase 3+). */
    public function aiRuns(): MorphMany
    {
        return $this->morphMany(AiRun::class, 'subject');
    }
}
