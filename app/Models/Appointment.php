<?php

namespace App\Models;

use App\Concerns\HasProvenance;
use App\Concerns\LogsEvents;
use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A meeting/appointment. Google Calendar integration is link-out only in v1
 * (see {@see googleCalendarUrl()}); two-way OAuth sync is a later decision.
 *
 * @property AppointmentStatus $status
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 */
class Appointment extends Model
{
    use HasFactory, HasProvenance, LogsEvents, SoftDeletes;

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'company_id',
        'contact_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'location',
        'status',
        'organizer_id',
        'google_event_id',
        'google_html_link',
        'source',
        'ai_action_id',
        'created_by',
    ];

    /**
     * @var array<int, string>
     */
    protected array $auditRedacted = ['description', 'location'];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * An "Add to Google Calendar" link-out URL (v1 — no API sync). Defaults to a
     * one-hour slot when no end time is set.
     */
    public function googleCalendarUrl(): string
    {
        $format = fn (?Carbon $dt): ?string => $dt?->clone()->utc()->format('Ymd\THis\Z');

        $start = $this->starts_at;
        $end = $this->ends_at ?? $this->starts_at?->clone()->addHour();

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $this->title,
            'dates' => $format($start).'/'.$format($end),
            'details' => (string) $this->description,
            'location' => (string) $this->location,
        ]);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
