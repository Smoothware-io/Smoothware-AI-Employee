<?php

use App\Enums\CampaignStatus;
use App\Enums\SuppressionType;
use App\Models\AvailabilityRule;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\SonetelAccount;
use App\Models\User;
use App\Services\Outbound\CampaignRunner;
use App\Services\SuppressionList;
use Illuminate\Support\Carbon;

/**
 * The runner works a list. The expensive mistakes here are all about volume and
 * repetition: ringing fifty people at once, ringing the same person twice, or
 * ringing anyone at nine in the evening.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00')); // Monday, mid-morning

    AvailabilityRule::create([
        'weekday' => 1, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00', 'is_active' => true,
    ]);

    config([
        'outbound.enabled' => true,
        'outbound.originator' => 'fake',
        'outbound.disclosure' => 'Je spreekt met een AI-assistent.',
        'outbound.register_screening' => 'tps',
        'outbound.allow_any_number' => true,
        'outbound.test_numbers' => [],
        'outbound.openai.project_id' => 'proj_test',
        'outbound.openai.key' => 'sk-test',
    ]);

    $this->rep = User::factory()->create();
    SonetelAccount::create([
        'user_id' => $this->rep->getKey(),
        'username' => 'rep@smoothware.io',
        'sonetel_number' => '+31201234567',
        'access_token' => 'tok',
        'expires_at' => now()->addHour(),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function campaign(array $overrides = []): Campaign
{
    return Campaign::create(array_merge([
        'name' => 'Test list',
        'status' => CampaignStatus::Running,
        'calls_per_hour' => 6,
        'max_call_minutes' => 3,
        'max_attempts' => 2,
        'retry_after_hours' => 24,
        'respect_working_hours' => true,
        'created_by' => test()->rep->getKey(),
    ], $overrides));
}

function lead(Campaign $c, string $phone = '+31612345678'): Company
{
    return Company::factory()->create([
        'campaign_id' => $c->getKey(),
        'phone' => $phone,
    ]);
}

it('places one call per tick, never the whole list at once', function () {
    // A loop that dials everything it can is a robocall wearing a for-each.
    $c = campaign();
    lead($c, '+31611111111');
    lead($c, '+31622222222');
    lead($c, '+31633333333');

    expect(app(CampaignRunner::class)->tick())->toBe(1)
        ->and(Call::where('direction', 'outbound')->count())->toBe(1);
});

it('waits for the pace before placing the next call', function () {
    $c = campaign(['calls_per_hour' => 6]); // one every ten minutes
    lead($c, '+31611111111');
    lead($c, '+31622222222');

    app(CampaignRunner::class)->tick();

    // A minute later: not due.
    Carbon::setTestNow(now()->addMinute());
    expect(app(CampaignRunner::class)->tick())->toBe(0);

    // Eleven minutes later: due.
    Carbon::setTestNow(now()->addMinutes(10));
    expect(app(CampaignRunner::class)->tick())->toBe(1);
});

it('does not call outside working hours', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 21:00:00')); // Monday evening
    $c = campaign();
    lead($c);

    expect(app(CampaignRunner::class)->tick())->toBe(0);
});

it('calls outside working hours when a campaign says it may', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 21:00:00'));
    $c = campaign(['respect_working_hours' => false]);
    lead($c);

    expect(app(CampaignRunner::class)->tick())->toBe(1);
});

it('never rings somebody the AI already spoke to', function () {
    // Ringing a lead a second time to say the same thing is how a prospect
    // becomes a complaint.
    $c = campaign();
    $company = lead($c);

    Call::create([
        'company_id' => $company->getKey(),
        'direction' => 'outbound',
        'status' => 'completed',
        'started_at' => now()->subDays(3),
    ]);

    expect(app(CampaignRunner::class)->nextTarget($c))->toBeNull();
});

it('waits the retry window before trying a missed call again', function () {
    $c = campaign(['retry_after_hours' => 24]);
    $company = lead($c);

    Call::create([
        'company_id' => $company->getKey(),
        'direction' => 'outbound',
        'status' => 'no_answer',
        'started_at' => now()->subHours(2),
    ]);

    expect(app(CampaignRunner::class)->nextTarget($c))->toBeNull();

    // The window runs from when the attempt was RECORDED, not from started_at:
    // Eloquent stamps created_at itself, so 23 hours on is still inside a
    // 24-hour window. 25 is the first hour it is genuinely due.
    Carbon::setTestNow(now()->addHours(25));
    expect(app(CampaignRunner::class)->nextTarget($c)?->getKey())->toBe($company->getKey());
});

it('gives up on a company after the configured number of attempts', function () {
    $c = campaign(['max_attempts' => 2, 'retry_after_hours' => 1]);
    $company = lead($c);

    foreach ([5, 4] as $daysAgo) {
        Call::create([
            'company_id' => $company->getKey(),
            'direction' => 'outbound',
            'status' => 'no_answer',
            'started_at' => now()->subDays($daysAgo),
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    expect(app(CampaignRunner::class)->nextTarget($c))->toBeNull();
});

it('ignores companies with no phone number', function () {
    $c = campaign();
    Company::factory()->create(['campaign_id' => $c->getKey(), 'phone' => null]);

    expect(app(CampaignRunner::class)->nextTarget($c))->toBeNull();
});

it('finishes the campaign when nobody is left', function () {
    // "Running" forever with nothing happening reads as broken.
    $c = campaign();

    app(CampaignRunner::class)->tick();

    expect($c->fresh()->status)->toBe(CampaignStatus::Completed)
        ->and($c->fresh()->completed_at)->not->toBeNull();
});

it('does not dial a paused or draft campaign', function () {
    foreach ([CampaignStatus::Draft, CampaignStatus::Paused] as $status) {
        $c = campaign(['status' => $status]);
        lead($c, '+3161111'.$status->value);

        expect(app(CampaignRunner::class)->advance($c))->toBeFalse();
    }

    expect(Call::where('direction', 'outbound')->count())->toBe(0);
});

it('moves past a company it is not allowed to call, rather than stalling', function () {
    // One un-callable row must not wedge the whole campaign by being picked
    // again on every single tick.
    $c = campaign();
    $blocked = lead($c, '+31699999999');
    app(SuppressionList::class)
        ->suppress(SuppressionType::Phone, '+31699999999');
    $good = lead($c, '+31611111111');

    app(CampaignRunner::class)->tick();   // refused, recorded as attempted
    Carbon::setTestNow(now()->addMinutes(11));
    app(CampaignRunner::class)->tick();   // reaches the next one

    expect(Call::where('company_id', $good->getKey())->exists())->toBeTrue()
        ->and(Call::where('company_id', $blocked->getKey())->where('status', 'failed')->exists())
        ->toBeTrue();
});

it('reports progress a human can read', function () {
    $c = campaign();
    $reached = lead($c, '+31611111111');
    lead($c, '+31622222222');
    Company::factory()->create(['campaign_id' => $c->getKey(), 'phone' => null]);

    Call::create([
        'company_id' => $reached->getKey(),
        'direction' => 'outbound',
        'status' => 'completed',
        'started_at' => now(),
    ]);

    $p = app(CampaignRunner::class)->progress($c);

    expect($p['total'])->toBe(3)
        ->and($p['callable'])->toBe(2)
        ->and($p['no_phone'])->toBe(1)
        ->and($p['reached'])->toBe(1)
        ->and($p['remaining'])->toBe(1);
});
