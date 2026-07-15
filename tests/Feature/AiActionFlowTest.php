<?php

use App\Enums\ActorType;
use App\Enums\AiActionStatus;
use App\Enums\AiActionType;
use App\Exceptions\InvalidAiActionTransition;
use App\Models\AiAction;
use App\Models\Event;
use App\Models\User;
use App\Services\AiActionService;

beforeEach(function () {
    $this->service = app(AiActionService::class);
    $this->reviewer = User::factory()->create();
});

it('proposes an action as a draft, logged to the AI agent', function () {
    $action = $this->service->propose(
        AiActionType::CreateCompany,
        ['name' => 'Acme BV'],
        ['confidence_score' => 0.87, 'source_context_version' => 'kb-v1', 'model_id' => 'claude-opus-4-8'],
    );

    expect($action->status)->toBe(AiActionStatus::Draft)
        ->and($action->action_type)->toBe('create_company')
        ->and($action->proposed_payload)->toBe(['name' => 'Acme BV'])
        ->and((float) $action->confidence_score)->toBe(0.87)
        ->and($action->isApplied())->toBeFalse();

    $event = Event::where('action', 'ai_action.proposed')->first();
    expect($event->actor_type)->toBe(ActorType::AiAgent);
});

it('approves a draft and records the reviewer', function () {
    $action = $this->service->propose(AiActionType::CreateNote, ['body' => 'hi']);

    $this->service->approve($action, $this->reviewer, 'looks right');

    expect($action->status)->toBe(AiActionStatus::Approved)
        ->and($action->reviewed_by)->toBe($this->reviewer->id)
        ->and($action->reviewed_at)->not->toBeNull()
        ->and(Event::where('action', 'ai_action.approved')->exists())->toBeTrue();
});

it('rejects a draft with a reason and never applies it', function () {
    $action = $this->service->propose(AiActionType::CreateNote, ['body' => 'nope']);

    $this->service->reject($action, $this->reviewer, 'hallucinated contact');

    expect($action->status)->toBe(AiActionStatus::Rejected)
        ->and($action->review_notes)->toBe('hallucinated contact')
        ->and($action->isApplied())->toBeFalse();
});

it('refuses to approve anything that is not a draft', function () {
    $action = $this->service->propose(AiActionType::CreateNote, ['body' => 'x']);
    $this->service->reject($action, $this->reviewer, 'no');

    expect(fn () => $this->service->approve($action, $this->reviewer))
        ->toThrow(InvalidAiActionTransition::class);
});

it('refuses to apply a draft that was never approved', function () {
    $action = $this->service->propose(AiActionType::CreateCompany, ['name' => 'X']);

    expect(fn () => $this->service->apply($action, fn () => null))
        ->toThrow(InvalidAiActionTransition::class);
});

it('applies an approved action, executing the side effect and recording the target', function () {
    $action = $this->service->propose(AiActionType::CreateCompany, ['name' => 'Acme BV']);
    $this->service->approve($action, $this->reviewer);

    // The executor stands in for a real "create the Company" step.
    $created = User::factory()->create(['name' => 'Acme BV']);
    $this->service->apply($action, fn () => $created);

    expect($action->isApplied())->toBeTrue()
        ->and($action->applied_at)->not->toBeNull()
        ->and($action->target_id)->toBe($created->id)
        ->and($action->target_type)->toBe($created->getMorphClass())
        ->and(Event::where('action', 'ai_action.applied')->exists())->toBeTrue();
});

it('refuses to apply the same action twice', function () {
    $action = $this->service->propose(AiActionType::CreateCompany, ['name' => 'Acme BV']);
    $this->service->approveAndApply($action, $this->reviewer, fn () => User::factory()->create());

    expect(fn () => $this->service->apply($action, fn () => User::factory()->create()))
        ->toThrow(InvalidAiActionTransition::class);
});

it('supports the earned-autonomy auto-apply path, still audited', function () {
    $created = User::factory()->create();

    $action = $this->service->autoApply(
        AiActionType::CreateContact,
        ['name' => 'Jan'],
        fn () => $created,
    );

    expect($action->status)->toBe(AiActionStatus::AutoApplied)
        ->and($action->isApplied())->toBeTrue()
        ->and($action->reviewed_by)->toBeNull()
        ->and($action->target_id)->toBe($created->id);
});

it('exposes a pending-review scope for the queue', function () {
    $draft = $this->service->propose(AiActionType::CreateNote, ['body' => 'a']);
    $rejected = $this->service->propose(AiActionType::CreateNote, ['body' => 'b']);
    $this->service->reject($rejected, $this->reviewer, 'no');

    $pending = AiAction::pendingReview()->pluck('id');

    expect($pending)->toContain($draft->id)
        ->and($pending)->not->toContain($rejected->id);
});
