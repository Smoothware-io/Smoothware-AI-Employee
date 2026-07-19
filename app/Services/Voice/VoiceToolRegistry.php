<?php

namespace App\Services\Voice;

use App\Enums\AppointmentStatus;
use App\Enums\NoteCategory;
use App\Enums\RecordSource;
use App\Models\Appointment;
use App\Models\Call;
use App\Models\Company;
use App\Models\Note;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The tools the AI may call on a live call, and how each one runs.
 *
 * Schema and handler live TOGETHER, on purpose. If the JSON schema the model
 * sees (declared in the accept payload) and the code that executes the call
 * lived apart, they would drift — the model would call a tool with arguments the
 * handler no longer expects, and it would fail at the worst possible moment,
 * mid-conversation with a real person. One definition, two readers.
 *
 * Adding a tool is a change to THIS class only. go-voice never learns the tool
 * exists; it just forwards whatever the model calls (ARCHITECTURE §15.6).
 *
 * On provenance (ARCHITECTURE §14): a live AI cannot go through the
 * propose→approve queue — you cannot approve a sentence already spoken, or a
 * meeting the caller just agreed to. So these writes happen directly, but tagged
 * `RecordSource::Ai` — visibly AI-created (amber badge), fully auditable, and a
 * human can undo them. The safeguard is grounding + visibility, not approval.
 */
class VoiceToolRegistry
{
    /**
     * The tool schemas as OpenAI's Realtime session expects them. Handed to the
     * model in the accept payload so it knows what it can call.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schemas(): array
    {
        return [
            [
                'type' => 'function',
                'name' => 'get_available_times',
                'description' => 'Look up free appointment slots to offer the caller. Call this before proposing a time, never invent availability.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'from_date' => [
                            'type' => 'string',
                            'description' => 'Earliest date to look from, YYYY-MM-DD. Defaults to today.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'type' => 'function',
                'name' => 'book_appointment',
                'description' => 'Book an appointment once the caller has agreed to a specific time. Only use a time returned by get_available_times.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'starts_at' => [
                            'type' => 'string',
                            'description' => 'Start time in ISO 8601, e.g. 2026-07-21T14:00:00.',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Short subject, e.g. "Intro call about website".',
                        ],
                        'duration_minutes' => [
                            'type' => 'integer',
                            'description' => 'Length in minutes. Defaults to the standard slot.',
                        ],
                    ],
                    'required' => ['starts_at', 'title'],
                ],
            ],
            [
                'type' => 'function',
                'name' => 'add_note',
                'description' => 'Record something the caller said that the team should know — a requirement, an objection, a callback request.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'body' => ['type' => 'string', 'description' => 'The note text.'],
                    ],
                    'required' => ['body'],
                ],
            ],
        ];
    }

    /**
     * Run a tool the AI called. Returns an array that becomes the model-visible
     * output — including on failure, because the model turns an {error:...} into
     * a graceful "I can't do that right now" rather than dead air.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(string $name, array $args, ?Call $call): array
    {
        try {
            return match ($name) {
                'get_available_times' => $this->availableTimes($args),
                'book_appointment' => $this->bookAppointment($args, $call),
                'add_note' => $this->addNote($args, $call),
                default => ['error' => "unknown tool: {$name}"],
            };
        } catch (RuntimeException $e) {
            // Expected, caller-facing failures (no company, bad time) — a clean
            // message the AI can voice.
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Free slots across business hours, minus anything already booked. Small and
     * synchronous on purpose: the caller is holding the line while this runs.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function availableTimes(array $args): array
    {
        $cfg = (array) config('voice.booking');
        $open = (int) $cfg['open_hour'];
        $close = (int) $cfg['close_hour'];
        $slot = (int) $cfg['slot_minutes'];
        $horizon = (int) $cfg['horizon_days'];

        $from = $this->parseDate($args['from_date'] ?? null) ?? Carbon::today();
        $until = Carbon::today()->addDays($horizon);

        // One query for the whole window rather than per-slot — the taken set is
        // small and checking in PHP beats N round-trips while a human waits.
        $taken = Appointment::query()
            ->whereBetween('starts_at', [$from->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->whereIn('status', [AppointmentStatus::Scheduled->value])
            ->pluck('starts_at')
            ->map(fn (Carbon $t) => $t->format('Y-m-d H:i'))
            ->flip();

        $slots = [];
        $cursor = $from->copy()->max(Carbon::today());

        for ($day = $cursor->copy(); $day->lte($until) && count($slots) < 6; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            for ($h = $open; $h < $close; $h += max(1, intdiv($slot, 60)) ?: 1) {
                for ($m = 0; $m < 60; $m += $slot) {
                    if ($h === $close - 1 && $m + $slot > 60) {
                        break;
                    }
                    $when = $day->copy()->setTime($h, $m);
                    if ($when->isPast()) {
                        continue;
                    }
                    if ($taken->has($when->format('Y-m-d H:i'))) {
                        continue;
                    }
                    $slots[] = $when->toIso8601String();
                    if (count($slots) >= 6) {
                        break 3;
                    }
                }
            }
        }

        return ['available' => $slots, 'slot_minutes' => $slot];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function bookAppointment(array $args, ?Call $call): array
    {
        $company = $this->resolveCompany($call);
        if ($company === null) {
            // Honest failure: no company means nowhere to hang the meeting.
            throw new RuntimeException('I could not find which company this call is for, so I cannot book yet.');
        }

        $starts = $this->parseDateTime($args['starts_at'] ?? null);
        if ($starts === null || $starts->isPast()) {
            throw new RuntimeException('That time is not valid or is in the past.');
        }

        $minutes = (int) ($args['duration_minutes'] ?? config('voice.booking.slot_minutes', 30));

        $appointment = Appointment::create([
            'company_id' => $company->getKey(),
            'contact_id' => $call?->contact_id,
            'title' => (string) ($args['title'] ?? 'Appointment'),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes(max(15, $minutes)),
            'status' => AppointmentStatus::Scheduled,
            // AI-created, no approval queue (see class docblock / §14). Tagged so
            // it renders as AI and a human can review or undo it.
            'source' => RecordSource::Ai,
        ]);

        return [
            'booked' => true,
            'appointment_id' => $appointment->getKey(),
            'starts_at' => $starts->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function addNote(array $args, ?Call $call): array
    {
        $company = $this->resolveCompany($call);
        if ($company === null) {
            throw new RuntimeException('I could not find which company this call is for, so I cannot save that.');
        }

        $body = trim((string) ($args['body'] ?? ''));
        if ($body === '') {
            throw new RuntimeException('There was nothing to note.');
        }

        Note::create([
            'company_id' => $company->getKey(),
            'category' => NoteCategory::Internal,
            'body' => $body,
            'source' => RecordSource::Ai,
        ]);

        return ['saved' => true];
    }

    /**
     * Which company does this call's work belong to?
     *
     * A stranger ringing the number has no company until a human matches them —
     * the normal case for inbound, not a test artefact. Refusing outright is
     * safe but loses a real lead's request the moment they hang up, so work is
     * filed against one designated holding company instead.
     *
     * Created LAZILY, and only here: a call where the AI books nothing leaves no
     * junk company behind. The Call is linked to it as well, so the appointment,
     * the note and the call all point at the same record for a human to
     * re-assign later rather than three orphans.
     */
    private function resolveCompany(?Call $call): ?Company
    {
        if ($call?->company !== null) {
            return $call->company;
        }

        if (! config('voice.fallback_company.enabled', true)) {
            return null;
        }

        $company = Company::firstOrCreate(
            ['name' => (string) config('voice.fallback_company.name', 'Onbekende beller')],
            // System, not Ai: this is a filing cabinet the system opened, not the
            // AI asserting that a company by this name exists in the world.
            ['source' => RecordSource::System],
        );

        // Link it back so the call and everything booked on it agree.
        if ($call !== null && $call->company_id === null) {
            $call->forceFill(['company_id' => $company->getKey()])->saveQuietly();
            $call->setRelation('company', $company);
        }

        return $company;
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDateTime(?string $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
