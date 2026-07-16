<?php

use App\Enums\AnalysisPriority;
use App\Enums\AssigneeStrategy;
use App\Enums\CompanyStatus;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpTrigger;
use App\Enums\RecordSource;
use App\Enums\TaskStatus;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\CompanyAiAnalysis;
use App\Models\Event;
use App\Models\FollowUp;
use App\Models\FollowUpRule;
use App\Models\Task;
use App\Models\User;
use App\Services\FollowUps\FollowUpEvaluator;
use Illuminate\Database\QueryException;

/**
 * Phase 7 — follow-up automation. The queue runs sync in tests, so logging an
 * event drives the listener -> job -> evaluator chain inline.
 */
function evaluator(): FollowUpEvaluator
{
    return app(FollowUpEvaluator::class);
}

// --- Event-driven firing --------------------------------------------------

it('creates a task when a rule fires, tagged system and with no ai_action', function () {
    $owner = User::factory()->create();
    $company = Company::factory()->create(['owner_id' => $owner->id]);
    $rule = FollowUpRule::factory()->create(['task_title' => 'Follow up with {company.name}']);

    Call::factory()->for($company)->create(); // logs call.created -> CallLogged

    $followUp = FollowUp::firstWhere('company_id', $company->id);

    expect($followUp)->not->toBeNull()
        ->and($followUp->status)->toBe(FollowUpStatus::Applied)
        ->and($followUp->source)->toBe(RecordSource::System)
        // A human wrote the rule, so this is NOT an AI action awaiting approval.
        ->and($followUp->ai_action_id)->toBeNull()
        ->and($followUp->follow_up_rule_id)->toBe($rule->id);

    $task = $followUp->task;

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe("Follow up with {$company->name}")
        ->and($task->source)->toBe(RecordSource::System)
        ->and($task->assigned_to)->toBe($owner->id)
        ->and($task->status)->toBe(TaskStatus::Open);
});

it('links the follow-up back to the exact event that fired it', function () {
    $company = Company::factory()->create();
    FollowUpRule::factory()->create();

    Call::factory()->for($company)->create();

    $followUp = FollowUp::firstWhere('company_id', $company->id);

    expect($followUp->triggerEvent)->not->toBeNull()
        ->and($followUp->triggerEvent->action)->toBe('call.created');
});

it('does not fire inactive rules', function () {
    $company = Company::factory()->create();
    FollowUpRule::factory()->inactive()->create();

    Call::factory()->for($company)->create();

    expect(FollowUp::count())->toBe(0)
        ->and(Task::count())->toBe(0);
});

it('only fires rules whose trigger the event satisfies', function () {
    $company = Company::factory()->create();
    FollowUpRule::factory()->trigger(FollowUpTrigger::AnalysisGenerated)->create();

    Call::factory()->for($company)->create(); // call.created != analysis_generated

    expect(FollowUp::count())->toBe(0);
});

it('maps a completed task to the TaskCompleted trigger, but an open one to nothing', function () {
    $company = Company::factory()->create();
    FollowUpRule::factory()->trigger(FollowUpTrigger::TaskCompleted)->create();

    $task = Task::factory()->for($company)->create(['status' => TaskStatus::Open]);
    expect(FollowUp::count())->toBe(0);   // task.created fires nothing

    $task->start();                        // in_progress — still not completed
    expect(FollowUp::count())->toBe(0);

    $task->complete();

    expect(FollowUp::count())->toBe(1);
});

it('treats an imported company as CompanyImported but a manual one as nothing', function () {
    FollowUpRule::factory()->trigger(FollowUpTrigger::CompanyImported)->create();

    Company::factory()->create(['source' => RecordSource::Manual]);
    expect(FollowUp::count())->toBe(0);

    Company::factory()->create(['source' => RecordSource::Import]);
    expect(FollowUp::count())->toBe(1);
});

// --- Idempotency ----------------------------------------------------------

it('does not create a duplicate follow-up when the same event is evaluated twice', function () {
    $company = Company::factory()->create();
    FollowUpRule::factory()->create();

    Call::factory()->for($company)->create();
    expect(FollowUp::count())->toBe(1);

    // Re-run the evaluator over the same event, as a retried job would.
    $event = Event::firstWhere('action', 'call.created');
    evaluator()->forEvent($event);
    evaluator()->forEvent($event);

    expect(FollowUp::count())->toBe(1)
        ->and(Task::count())->toBe(1);
});

it('enforces idempotency at the database, not just in code', function () {
    $company = Company::factory()->create();
    $rule = FollowUpRule::factory()->create();

    $key = FollowUp::dedupKey($rule->id, $company->id, 'event:1');
    FollowUp::create(['company_id' => $company->id, 'trigger' => $rule->trigger, 'dedup_key' => $key]);

    // The UNIQUE index is the real guard — two racing workers must not both win.
    expect(fn () => FollowUp::create([
        'company_id' => $company->id,
        'trigger' => $rule->trigger,
        'dedup_key' => $key,
    ]))->toThrow(QueryException::class);
});

// --- Rule snapshot --------------------------------------------------------

it('freezes the rule as it read when it fired, so later edits cannot rewrite history', function () {
    $company = Company::factory()->create();
    $rule = FollowUpRule::factory()->create(['name' => 'Original name', 'delay_minutes' => 60]);

    Call::factory()->for($company)->create();

    $rule->update(['name' => 'Renamed later', 'delay_minutes' => 9999, 'task_title' => 'Different']);

    $snapshot = FollowUp::firstWhere('company_id', $company->id)->rule_snapshot;

    expect($snapshot['name'])->toBe('Original name')
        ->and($snapshot['delay_minutes'])->toBe(60);
});

// --- Conditions -----------------------------------------------------------

it('fires only for companies matching company_status', function () {
    FollowUpRule::factory()->create(['conditions' => ['company_status' => ['lead']]]);

    $lead = Company::factory()->create(['status' => CompanyStatus::Lead]);
    $customer = Company::factory()->create(['status' => CompanyStatus::Customer]);

    Call::factory()->for($customer)->create();
    expect(FollowUp::count())->toBe(0);

    Call::factory()->for($lead)->create();
    expect(FollowUp::count())->toBe(1);
});

it('fires only for companies in the rule\'s campaign', function () {
    $campaign = Campaign::factory()->create();
    FollowUpRule::factory()->create(['conditions' => ['campaign_id' => $campaign->id]]);

    Call::factory()->for(Company::factory()->create())->create();          // no campaign
    expect(FollowUp::count())->toBe(0);

    Call::factory()->for(Company::factory()->create(['campaign_id' => $campaign->id]))->create();
    expect(FollowUp::count())->toBe(1);
});

it('respects a min_ai_priority floor, and does not fire without an analysis', function () {
    FollowUpRule::factory()->create(['conditions' => ['min_ai_priority' => 'high']]);

    $noAnalysis = Company::factory()->create();
    Call::factory()->for($noAnalysis)->create();
    expect(FollowUp::count())->toBe(0);

    $low = Company::factory()->create();
    CompanyAiAnalysis::factory()->for($low)->create(['inferred_priority' => AnalysisPriority::Low]);
    Call::factory()->for($low)->create();
    expect(FollowUp::count())->toBe(0);

    $high = Company::factory()->create();
    CompanyAiAnalysis::factory()->for($high)->create(['inferred_priority' => AnalysisPriority::High]);
    Call::factory()->for($high)->create();
    expect(FollowUp::count())->toBe(1);
});

it('matches everything when the rule has no conditions', function () {
    FollowUpRule::factory()->create(['conditions' => null]);

    Call::factory()->for(Company::factory()->create(['status' => CompanyStatus::Dormant]))->create();

    expect(FollowUp::count())->toBe(1);
});

// --- Assignee strategies --------------------------------------------------

it('resolves the assignee at fire time, following ownership changes', function () {
    $original = User::factory()->create();
    $newOwner = User::factory()->create();
    $company = Company::factory()->create(['owner_id' => $original->id]);

    FollowUpRule::factory()->create(['assignee_strategy' => AssigneeStrategy::CompanyOwner]);

    // Ownership changes AFTER the rule was written — the task must follow it.
    $company->update(['owner_id' => $newOwner->id]);
    Call::factory()->for($company)->create();

    expect(FollowUp::firstWhere('company_id', $company->id)->task->assigned_to)->toBe($newOwner->id);
});

it('supports the rule-creator, specific-user and unassigned strategies', function (AssigneeStrategy $strategy, string $expected) {
    $creator = User::factory()->create();
    $specific = User::factory()->create();
    $owner = User::factory()->create();
    $company = Company::factory()->create(['owner_id' => $owner->id]);

    FollowUpRule::factory()->create([
        'assignee_strategy' => $strategy,
        'created_by' => $creator->id,
        'assignee_id' => $specific->id,
    ]);

    Call::factory()->for($company)->create();

    $assigned = FollowUp::firstWhere('company_id', $company->id)->task->assigned_to;

    expect($assigned)->toBe(match ($expected) {
        'creator' => $creator->id,
        'specific' => $specific->id,
        'none' => null,
    });
})->with([
    [AssigneeStrategy::RuleCreator, 'creator'],
    [AssigneeStrategy::SpecificUser, 'specific'],
    [AssigneeStrategy::Unassigned, 'none'],
]);

it('leaves the task unassigned when the company has no owner', function () {
    $company = Company::factory()->create(['owner_id' => null]);
    FollowUpRule::factory()->create(['assignee_strategy' => AssigneeStrategy::CompanyOwner]);

    Call::factory()->for($company)->create();

    expect(FollowUp::firstWhere('company_id', $company->id)->task->assigned_to)->toBeNull();
});

// --- Spam guard -----------------------------------------------------------

it('caps follow-ups per company per day and records the suppressed ones as skipped', function () {
    config(['followups.max_per_company_per_day' => 2]);

    $company = Company::factory()->create();
    FollowUpRule::factory()->create();

    Call::factory()->for($company)->create();
    Call::factory()->for($company)->create();
    Call::factory()->for($company)->create(); // over the cap

    expect(FollowUp::where('status', FollowUpStatus::Applied)->count())->toBe(2)
        ->and(Task::count())->toBe(2)
        // Suppression is a decision, so it is recorded rather than silently dropped.
        ->and(FollowUp::where('status', FollowUpStatus::Skipped)->count())->toBe(1)
        ->and(FollowUp::where('status', FollowUpStatus::Skipped)->first()->reason)->toContain('cap');
});

// --- NoActivity sweep -----------------------------------------------------

it('fires for a company that has gone quiet past the window', function () {
    config(['followups.no_activity_days' => 14]);
    FollowUpRule::factory()->trigger(FollowUpTrigger::NoActivity)->create();

    $quiet = Company::factory()->create();
    // Age every event this company has, so its latest activity is outside the window.
    Event::where('company_id', $quiet->id)->update(['created_at' => now()->subDays(20)]);

    expect(evaluator()->sweepNoActivity())->toBe(1);
    expect(FollowUp::where('company_id', $quiet->id)->count())->toBe(1);
});

it('does not fire for a company that is still active', function () {
    config(['followups.no_activity_days' => 14]);
    FollowUpRule::factory()->trigger(FollowUpTrigger::NoActivity)->create();

    Company::factory()->create(); // its company.created event is right now

    expect(evaluator()->sweepNoActivity())->toBe(0);
    expect(FollowUp::count())->toBe(0);
});

it('does not fire twice in one day for the same quiet company', function () {
    FollowUpRule::factory()->trigger(FollowUpTrigger::NoActivity)->create();

    $quiet = Company::factory()->create();
    Event::where('company_id', $quiet->id)->update(['created_at' => now()->subDays(20)]);

    evaluator()->sweepNoActivity();
    evaluator()->sweepNoActivity(); // the daily job re-running

    expect(FollowUp::where('company_id', $quiet->id)->count())->toBe(1);
});

it('ignores archived companies in the sweep', function () {
    FollowUpRule::factory()->trigger(FollowUpTrigger::NoActivity)->create();

    $company = Company::factory()->create();
    Event::where('company_id', $company->id)->update(['created_at' => now()->subDays(20)]);
    $company->delete(); // archived_at

    expect(evaluator()->sweepNoActivity())->toBe(0);
});
