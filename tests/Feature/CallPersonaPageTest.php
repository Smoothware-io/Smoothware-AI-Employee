<?php

use App\Filament\Resources\CallPersonas\Pages\EditCallPersona;
use App\Filament\Resources\CallPersonas\Pages\ListCallPersonas;
use App\Models\CallPersona;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Render the pages, not just the service behind them.
 *
 * Earlier today a fully green suite shipped a page that 500'd the moment it was
 * opened, because every test exercised the class behind the view and none of
 * them mounted the view. Same class of bug deserves the same class of test.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    actingAs($admin);

    Gate::before(fn () => true); // isolate render from authz (see RbacTest)
});

it('renders the persona list and seeds both directions', function () {
    Livewire::test(ListCallPersonas::class)->assertOk();

    // Both materialise on first view, so the page is never an empty table with
    // nothing to click and no hint that defaults already exist.
    expect(CallPersona::count())->toBe(2)
        ->and(CallPersona::pluck('direction')->map->value->sort()->values()->all())
        ->toBe(['inbound', 'outbound']);
});

it('renders the persona edit form with the current role', function () {
    $persona = CallPersona::create([
        'direction' => 'inbound',
        'role' => 'Answer the phone for Smoothware.',
    ]);

    Livewire::test(EditCallPersona::class, ['record' => $persona->getKey()])
        ->assertOk()
        // assertFormSet, not assertSee: a textarea's value is bound through
        // wire:model and never appears in the rendered HTML.
        ->assertFormSet(['role' => 'Answer the phone for Smoothware.']);
});

it('records who last changed what the AI says', function () {
    // This text reaches strangers on the phone; "who approved this wording" must
    // always have an answer.
    $persona = CallPersona::create(['direction' => 'outbound', 'role' => 'Original.']);

    Livewire::test(EditCallPersona::class, ['record' => $persona->getKey()])
        ->fillForm(['role' => 'Rewritten by a human.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($persona->fresh()->updated_by)->not->toBeNull();
});
