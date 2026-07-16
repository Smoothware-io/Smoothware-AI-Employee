<?php

use App\Enums\FollowUpTrigger;
use App\Jobs\EvaluateFollowUpsForEvent;
use App\Models\Call;
use App\Models\Company;
use App\Models\FollowUpRule;
use Illuminate\Support\Facades\Queue;

/**
 * The listener runs on EVERY write to the event log, so it must not queue work
 * that would find nothing to do. Verified on real Postgres before this gate
 * existed: 7 no-op evaluation jobs queued against 2 rules, because a trigger
 * mapping alone was enough to dispatch.
 *
 * The cache is the risky part — a stale answer here means rules silently stop
 * firing, which no other test would catch.
 */
beforeEach(function () {
    Queue::fake();
    FollowUpRule::forgetActiveTriggers();
});

it('queues nothing when no rules are configured at all', function () {
    Call::factory()->for(Company::factory()->create())->create();

    Queue::assertNothingPushed();
});

it('queues nothing when the only rule watches a different trigger', function () {
    FollowUpRule::factory()->trigger(FollowUpTrigger::AnalysisGenerated)->create();

    Call::factory()->for(Company::factory()->create())->create(); // call.created

    Queue::assertNotPushed(EvaluateFollowUpsForEvent::class);
});

it('queues nothing when the matching rule is inactive', function () {
    FollowUpRule::factory()->trigger(FollowUpTrigger::CallLogged)->inactive()->create();

    Call::factory()->for(Company::factory()->create())->create();

    Queue::assertNotPushed(EvaluateFollowUpsForEvent::class);
});

it('queues the evaluation when an active rule wants the trigger', function () {
    FollowUpRule::factory()->trigger(FollowUpTrigger::CallLogged)->create();

    Call::factory()->for(Company::factory()->create())->create();

    Queue::assertPushed(EvaluateFollowUpsForEvent::class, 1);
});

it('does not queue for an event that maps to no trigger', function () {
    FollowUpRule::factory()->trigger(FollowUpTrigger::CallLogged)->create();

    Company::factory()->create(); // company.created (manual) maps to nothing

    Queue::assertNotPushed(EvaluateFollowUpsForEvent::class);
});

// --- Cache invalidation ---------------------------------------------------

it('notices a rule that is created after the cache was warmed', function () {
    // Warm the cache with the "no rules" answer first.
    expect(FollowUpRule::activeTriggers())->toBe([]);

    FollowUpRule::factory()->trigger(FollowUpTrigger::CallLogged)->create();
    Call::factory()->for(Company::factory()->create())->create();

    Queue::assertPushed(EvaluateFollowUpsForEvent::class, 1);
});

it('notices a rule being deactivated', function () {
    $rule = FollowUpRule::factory()->trigger(FollowUpTrigger::CallLogged)->create();
    expect(FollowUpRule::hasActiveRuleFor(FollowUpTrigger::CallLogged))->toBeTrue();

    $rule->update(['is_active' => false]);

    expect(FollowUpRule::hasActiveRuleFor(FollowUpTrigger::CallLogged))->toBeFalse();

    Call::factory()->for(Company::factory()->create())->create();
    Queue::assertNotPushed(EvaluateFollowUpsForEvent::class);
});

it('notices a rule being archived and then restored', function () {
    $rule = FollowUpRule::factory()->trigger(FollowUpTrigger::CallLogged)->create();

    $rule->delete();
    expect(FollowUpRule::hasActiveRuleFor(FollowUpTrigger::CallLogged))->toBeFalse();

    $rule->restore();
    expect(FollowUpRule::hasActiveRuleFor(FollowUpTrigger::CallLogged))->toBeTrue();
});

it('reports only the triggers that actually have active rules', function () {
    FollowUpRule::factory()->trigger(FollowUpTrigger::CallLogged)->create();
    FollowUpRule::factory()->trigger(FollowUpTrigger::NoActivity)->create();
    FollowUpRule::factory()->trigger(FollowUpTrigger::TaskCompleted)->inactive()->create();

    expect(FollowUpRule::activeTriggers())
        ->toContain('call_logged')
        ->toContain('no_activity')
        ->not->toContain('task_completed');
});
