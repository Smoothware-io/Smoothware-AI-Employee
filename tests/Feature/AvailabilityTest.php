<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AvailabilityBlock;
use App\Models\AvailabilityRule;
use App\Models\Company;
use App\Services\Availability\AvailabilityCalculator;
use Illuminate\Support\Carbon;

/**
 * What the AI is allowed to offer, and what it must never offer.
 *
 * The expensive failure here is not "no slots" — it is offering a time that is
 * already taken or outside working hours, because that meeting is agreed out
 * loud with a stranger and nobody finds out until someone does not show up.
 */
beforeEach(function () {
    // A fixed Monday, so weekday arithmetic is not a coin flip on CI.
    Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00')); // Monday
});

afterEach(fn () => Carbon::setTestNow());

function rule(int $weekday, string $from = '09:00:00', string $to = '17:00:00'): AvailabilityRule
{
    return AvailabilityRule::create([
        'weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to, 'is_active' => true,
    ]);
}

it('offers slots inside the configured working hours', function () {
    rule(1, '09:00:00', '11:00:00'); // Monday only

    $slots = app(AvailabilityCalculator::class)->freeSlots();

    expect($slots)->not->toBeEmpty()
        ->and($slots[0]->format('Y-m-d H:i'))->toBe('2026-07-20 09:00')
        // 09:00-11:00 in 30-minute slots = four, and nothing past 10:30.
        ->and(collect($slots)->every(fn (Carbon $s): bool => $s->hour < 11))->toBeTrue();
});

it('never offers a day with no rule', function () {
    rule(3); // Wednesday only

    $slots = app(AvailabilityCalculator::class)->freeSlots();

    expect(collect($slots)->every(fn (Carbon $s): bool => $s->isoWeekday() === 3))->toBeTrue();
});

it('skips a slot that is already booked', function () {
    rule(1, '09:00:00', '11:00:00');
    $company = Company::factory()->create();

    Appointment::create([
        'company_id' => $company->getKey(),
        'title' => 'Taken',
        'starts_at' => Carbon::parse('2026-07-20 09:30'),
        'ends_at' => Carbon::parse('2026-07-20 10:00'),
        'status' => AppointmentStatus::Scheduled,
    ]);

    // Full date, not just the clock time: the rule repeats every Monday and the
    // fortnight horizon includes the NEXT one, whose 09:30 is genuinely free.
    $slots = collect(app(AvailabilityCalculator::class)->freeSlots())
        ->map(fn (Carbon $s): string => $s->format('Y-m-d H:i'));

    expect($slots)->toContain('2026-07-20 09:00')
        ->and($slots)->not->toContain('2026-07-20 09:30')
        ->and($slots)->toContain('2026-07-20 10:00');
});

it('skips time inside a block', function () {
    rule(1, '09:00:00', '12:00:00');

    AvailabilityBlock::create([
        'starts_at' => Carbon::parse('2026-07-20 10:00'),
        'ends_at' => Carbon::parse('2026-07-20 11:00'),
        'reason' => 'Team meeting',
    ]);

    $slots = collect(app(AvailabilityCalculator::class)->freeSlots())
        ->map(fn (Carbon $s): string => $s->format('Y-m-d H:i'));

    expect($slots)->toContain('2026-07-20 09:30')
        ->and($slots)->not->toContain('2026-07-20 10:00')
        ->and($slots)->not->toContain('2026-07-20 10:30')
        ->and($slots)->toContain('2026-07-20 11:00');
});

it('treats a slot ending exactly when a block starts as free', function () {
    // Half-open comparison. Get this wrong and 09:00-09:30 collides with a block
    // starting at 09:30, quietly halving every day.
    rule(1, '09:00:00', '12:00:00');

    AvailabilityBlock::create([
        'starts_at' => Carbon::parse('2026-07-20 09:30'),
        'ends_at' => Carbon::parse('2026-07-20 10:00'),
    ]);

    $slots = collect(app(AvailabilityCalculator::class)->freeSlots())
        ->map(fn (Carbon $s): string => $s->format('Y-m-d H:i'));

    expect($slots)->toContain('2026-07-20 09:00')
        ->and($slots)->not->toContain('2026-07-20 09:30');
});

it('never offers a time in the past', function () {
    rule(1, '06:00:00', '12:00:00'); // opens before "now" (08:00)

    $slots = app(AvailabilityCalculator::class)->freeSlots();

    expect(collect($slots)->every(fn (Carbon $s): bool => $s->isFuture()))->toBeTrue();
});

it('falls back to default business hours when nothing is configured', function () {
    // A fresh install must still be able to book, not refuse everything.
    expect(AvailabilityRule::count())->toBe(0);

    expect(app(AvailabilityCalculator::class)->freeSlots())->not->toBeEmpty();
});

it('ignores a rule that has been switched off', function () {
    rule(1)->update(['is_active' => false]);
    rule(2); // Tuesday

    $slots = app(AvailabilityCalculator::class)->freeSlots();

    expect(collect($slots)->every(fn (Carbon $s): bool => $s->isoWeekday() === 2))->toBeTrue();
});

it('refuses to confirm a time outside working hours', function () {
    rule(1, '09:00:00', '11:00:00');

    $calc = app(AvailabilityCalculator::class);

    expect($calc->isFree(Carbon::parse('2026-07-20 10:00'), 30))->toBeTrue()
        ->and($calc->isFree(Carbon::parse('2026-07-20 15:00'), 30))->toBeFalse()
        // A slot that starts inside hours but runs past closing is not free.
        ->and($calc->isFree(Carbon::parse('2026-07-20 10:45'), 30))->toBeFalse();
});

it('refuses to double-book at the moment of confirming', function () {
    // The caller deliberates; a colleague takes the slot meanwhile. The re-check
    // at booking time is what stops two people being promised the same half hour.
    rule(1, '09:00:00', '17:00:00');
    $company = Company::factory()->create();

    Appointment::create([
        'company_id' => $company->getKey(),
        'title' => 'Taken',
        'starts_at' => Carbon::parse('2026-07-20 14:00'),
        'ends_at' => Carbon::parse('2026-07-20 14:30'),
        'status' => AppointmentStatus::Scheduled,
    ]);

    expect(app(AvailabilityCalculator::class)->isFree(Carbon::parse('2026-07-20 14:00'), 30))
        ->toBeFalse();
});
