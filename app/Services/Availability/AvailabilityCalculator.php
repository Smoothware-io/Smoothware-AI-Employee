<?php

namespace App\Services\Availability;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AvailabilityBlock;
use App\Models\AvailabilityRule;
use App\Services\Google\GoogleBusyProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The free slots the AI may offer a caller.
 *
 * Five things narrow the answer, in order:
 *   1. the recurring weekly rules  — when we work at all
 *   2. one-off blocks             — holidays, days off, "at a conference"
 *   3. connected Google calendars — the meetings that live outside this system
 *   4. existing appointments      — nobody gets double-booked
 *   5. the present                — a slot in the past is not a slot
 *
 * Runs SYNCHRONOUSLY while a caller is holding the line, so it is deliberately
 * two queries and some arithmetic rather than anything clever. The window is
 * small (a fortnight, a handful of slots) and the cost of a wrong answer here is
 * a meeting nobody attends.
 *
 * `$userId` is threaded through everywhere but is null in practice today. That
 * is the seam for per-rep availability: when leads gain an owner, pass the
 * owner's id and the same code answers "when is SHE free" instead of "when are
 * WE free" — no rewrite, no migration of live appointments.
 */
class AvailabilityCalculator
{
    public function __construct(private GoogleBusyProvider $googleBusy) {}

    /**
     * @return array<int, Carbon> slot start times, soonest first
     */
    public function freeSlots(
        ?Carbon $from = null,
        ?int $userId = null,
        ?int $limit = null,
        ?int $slotMinutes = null,
        ?int $horizonDays = null,
    ): array {
        $cfg = (array) config('voice.booking');
        $slot = $slotMinutes ?? (int) $cfg['slot_minutes'];
        $horizon = $horizonDays ?? (int) $cfg['horizon_days'];
        $limit ??= 6;

        $from = ($from ?? Carbon::now())->copy();
        $start = $from->max(Carbon::now());
        $until = Carbon::today()->addDays($horizon)->endOfDay();

        $rules = $this->rules($userId);

        // No rules configured at all: fall back to the config-driven business
        // hours so a fresh install still books rather than refusing everything.
        if ($rules->isEmpty()) {
            $rules = $this->defaultRules($cfg);
        }

        $blocks = AvailabilityBlock::query()
            ->applicableTo($userId)
            ->where('ends_at', '>', $start)
            ->where('starts_at', '<', $until)
            ->get();

        // Meetings that exist only in the rep's own calendar. Fails open if
        // Google is unreachable — see GoogleBusyProvider for why.
        $googleBusy = $this->googleBusy->busy($start, $until, $userId);

        $taken = Appointment::query()
            ->where('starts_at', '>=', $start->copy()->startOfDay())
            ->where('starts_at', '<=', $until)
            ->where('status', AppointmentStatus::Scheduled->value)
            ->get(['starts_at', 'ends_at']);

        $slots = [];

        for ($day = $start->copy()->startOfDay(); $day->lte($until); $day->addDay()) {
            foreach ($rules->where('weekday', $day->isoWeekday()) as $rule) {
                $cursor = $this->at($day, $rule->starts_at);
                $dayEnd = $this->at($day, $rule->ends_at);

                while ($cursor->copy()->addMinutes($slot)->lte($dayEnd)) {
                    $slotEnd = $cursor->copy()->addMinutes($slot);

                    if ($cursor->gt($start)
                        && ! $this->overlapsAny($cursor, $slotEnd, $blocks)
                        && ! $this->overlapsAny($cursor, $slotEnd, $googleBusy)
                        && ! $this->overlapsAny($cursor, $slotEnd, $taken)) {
                        $slots[] = $cursor->copy();

                        if (count($slots) >= $limit) {
                            return $this->sorted($slots);
                        }
                    }

                    $cursor->addMinutes($slot);
                }
            }
        }

        return $this->sorted($slots);
    }

    /** Is one specific moment bookable? The check before actually booking it. */
    public function isFree(Carbon $start, int $slotMinutes, ?int $userId = null): bool
    {
        if ($start->isPast()) {
            return false;
        }

        $end = $start->copy()->addMinutes($slotMinutes);

        $rules = $this->rules($userId);
        if ($rules->isEmpty()) {
            $rules = $this->defaultRules((array) config('voice.booking'));
        }

        $withinHours = $rules
            ->where('weekday', $start->isoWeekday())
            ->contains(fn ($rule): bool => $start->gte($this->at($start, $rule->starts_at))
                && $end->lte($this->at($start, $rule->ends_at)));

        if (! $withinHours) {
            return false;
        }

        $blocks = AvailabilityBlock::query()
            ->applicableTo($userId)
            ->where('ends_at', '>', $start)
            ->where('starts_at', '<', $end)
            ->get();

        if ($this->overlapsAny($start, $end, $this->googleBusy->busy($start, $end, $userId))) {
            return false;
        }

        if ($this->overlapsAny($start, $end, $blocks)) {
            return false;
        }

        $taken = Appointment::query()
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get(['starts_at', 'ends_at']);

        return ! $this->overlapsAny($start, $end, $taken);
    }

    /** @return Collection<int, AvailabilityRule> */
    private function rules(?int $userId): Collection
    {
        return AvailabilityRule::query()->active()->applicableTo($userId)->get();
    }

    /**
     * Config-driven business hours as rule-shaped objects, used only when nobody
     * has configured any. Weekdays only — the previous hardcoded behaviour.
     *
     * @return Collection<int, AvailabilityRule>
     */
    private function defaultRules(array $cfg): Collection
    {
        return collect(range(1, 5))->map(fn (int $weekday): AvailabilityRule => new AvailabilityRule([
            'weekday' => $weekday,
            'starts_at' => sprintf('%02d:00:00', (int) $cfg['open_hour']),
            'ends_at' => sprintf('%02d:00:00', (int) $cfg['close_hour']),
            'is_active' => true,
        ]));
    }

    /** Combine a date with a "HH:MM:SS" time string. */
    private function at(Carbon $day, string $time): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $day->copy()->setTime($h, $m);
    }

    /**
     * Half-open overlap: a slot ending exactly when a block starts does NOT
     * collide. Get this wrong and 09:00–09:30 blocks 09:30–10:00, quietly
     * halving the day.
     *
     * @param  iterable<int, object{starts_at: Carbon, ends_at: Carbon}>  $periods
     */
    private function overlapsAny(Carbon $start, Carbon $end, iterable $periods): bool
    {
        foreach ($periods as $period) {
            if ($period->starts_at->lt($end) && $period->ends_at->gt($start)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, Carbon> */
    private function sorted(array $slots): array
    {
        usort($slots, fn (Carbon $a, Carbon $b): int => $a <=> $b);

        return $slots;
    }
}
