<?php

namespace App\Models;

use App\Concerns\HasProvenance;
use App\Concerns\LogsEvents;
use App\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A prospect or customer company — the hub of the CRM. Its detail page's
 * timeline is built from {@see timelineEvents()} (events anchored by company_id).
 *
 * @property CompanyStatus $status
 */
class Company extends Model
{
    use HasFactory, HasProvenance, LogsEvents, SoftDeletes;

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'name',
        'domain',
        'email',
        'phone',
        'address',
        'city',
        'postal_code',
        'country',
        'industry',
        'status',
        'owner_id',
        'campaign_id',
        'source',
        'ai_action_id',
        'created_by',
    ];

    /**
     * Company contact details can be personal data (sole traders), so their
     * values stay out of the append-only audit log.
     *
     * @var array<int, string>
     */
    protected array $auditRedacted = ['email', 'phone'];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }

    /** Company events anchor to the company itself. */
    public function eventTimelineCompanyId(): ?int
    {
        return (int) $this->getKey();
    }

    // --- Relationships -----------------------------------------------------

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /** Human-owned analysis (Phase 4) — one per company; AI never writes here. */
    public function manualAnalysis(): HasOne
    {
        return $this->hasOne(CompanyManualAnalysis::class);
    }

    /** AI-generated analyses (Phase 4), newest first — regeneration adds a row. */
    public function aiAnalyses(): HasMany
    {
        return $this->hasMany(CompanyAiAnalysis::class)->latest('generated_at');
    }

    public function latestAiAnalysis(): HasOne
    {
        return $this->hasOne(CompanyAiAnalysis::class)->latestOfMany('generated_at');
    }

    /** The activity feed for this company's detail page (Phase 1 timeline). */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(Event::class)->latest('created_at')->latest('id');
    }
}
