<?php

use App\Enums\AiActionStatus;
use App\Enums\RecordSource;
use App\Models\Call;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Services\AiActionService;
use App\Services\Receptionist\ReceptionistActionApplier;

function intakeDraft(array $overrides = [])
{
    $call = Call::factory()->create(['company_id' => null]);

    $payload = array_merge([
        'call_id' => $call->id,
        'intent' => 'sales_inquiry',
        'grounded' => true,
        'company' => ['match_id' => null, 'name' => 'De Vries Interieur', 'phone' => '+31201234567'],
        'contact' => ['first_name' => 'Sanne', 'last_name' => 'de Vries', 'phone' => '+31612345678'],
        'note' => ['category' => 'follow_up', 'body' => 'Wants a new website.'],
        'task' => ['type' => 'follow_up', 'title' => 'Follow up on inbound sales call'],
    ], $overrides);

    $action = app(AiActionService::class)->propose('receptionist_intake', $payload, ['confidence_score' => 0.8]);

    return [$action, $call];
}

it('creates AI-tagged records on approval and links the call', function () {
    [$action, $call] = intakeDraft();
    $reviewer = User::factory()->create();

    app(ReceptionistActionApplier::class)->approve($action, $reviewer);

    $action->refresh();
    expect($action->status)->toBe(AiActionStatus::Approved)
        ->and($action->isApplied())->toBeTrue();

    $company = Company::firstWhere('name', 'De Vries Interieur');
    expect($company)->not->toBeNull()
        ->and($company->source)->toBe(RecordSource::Ai)
        ->and($company->ai_action_id)->toBe($action->id);

    expect(Contact::where('company_id', $company->id)->where('source', RecordSource::Ai)->exists())->toBeTrue()
        ->and(Note::where('company_id', $company->id)->exists())->toBeTrue()
        ->and(Task::where('company_id', $company->id)->exists())->toBeTrue();

    // The factual call is now linked to the resolved company.
    expect($call->fresh()->company_id)->toBe($company->id);
});

it('links to an existing company instead of creating a duplicate', function () {
    $existing = Company::factory()->create();
    [$action] = intakeDraft(['company' => ['match_id' => $existing->id, 'name' => $existing->name]]);

    app(ReceptionistActionApplier::class)->approve($action, User::factory()->create());

    // Only the pre-existing company — the approval linked, it did not duplicate.
    expect(Company::count())->toBe(1)
        ->and($action->fresh()->target_id)->toBe($existing->id);
});

it('creates nothing when a draft is rejected', function () {
    [$action] = intakeDraft();

    app(ReceptionistActionApplier::class)->reject($action, User::factory()->create(), 'AI misread the caller');

    expect($action->fresh()->status)->toBe(AiActionStatus::Rejected)
        ->and(Company::firstWhere('name', 'De Vries Interieur'))->toBeNull();
});
