<?php

use App\Filament\Resources\AiActions\Pages\ListAiActions;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// `intakeDraft()` is a shared Pest helper defined in ReceptionistApprovalTest.php.

beforeEach(function () {
    actingAs(User::factory()->create());
    Gate::before(fn () => true); // isolate the UI wiring from Shield permissions
});

it('approves a draft from the review queue and creates the records', function () {
    [$action] = intakeDraft();

    Livewire::test(ListAiActions::class)
        ->callTableAction('approve', $action);

    expect($action->fresh()->isApplied())->toBeTrue()
        ->and(Company::firstWhere('name', 'De Vries Interieur'))->not->toBeNull();
});

it('rejects a draft from the review queue with a reason', function () {
    [$action] = intakeDraft();

    Livewire::test(ListAiActions::class)
        ->callTableAction('reject', $action, data: ['reason' => 'Caller was a wrong number']);

    expect($action->fresh()->isRejected())->toBeTrue()
        ->and(Company::firstWhere('name', 'De Vries Interieur'))->toBeNull();
});
