<?php

use App\Filament\Resources\CallPersonas\Pages\EditCallPersona;
use App\Models\CallPersona;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Render the page WITH the draft button visible.
 *
 * The existing page test passed while production 500'd, because the button is
 * hidden unless an AI provider is configured — and the test never configured
 * one. So the only state that could break was the only state never rendered.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    actingAs($admin);
    Gate::before(fn () => true);

    // The whole point: with a key set, the action renders.
    config(['services.anthropic.key' => 'test-key']);
});

it('renders the edit form with the AI draft button present', function () {
    $persona = CallPersona::create(['direction' => 'inbound', 'role' => 'Answer the phone.']);

    Livewire::test(EditCallPersona::class, ['record' => $persona->getKey()])
        ->assertOk()
        ->assertSee('Draft with AI');
});

it('actually runs when pressed, and fills the form', function () {
    // Rendering a button proves nothing about pressing it — the failure was here.
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'role' => 'Je neemt de telefoon op voor Smoothware.',
                'goal' => 'Begrijp de vraag en plan een gesprek in.',
            ])]],
        ]),
    ]);

    $persona = CallPersona::create(['direction' => 'inbound', 'role' => 'Old text.']);

    Livewire::test(EditCallPersona::class, ['record' => $persona->getKey()])
        ->fillForm(['preset' => 'sales'])
        // The action lives inside the form schema, so it must be addressed as a
        // schema component action rather than a page-level one.
        ->mountAction(TestAction::make('draft')->schemaComponent(true, 'form'))
        ->assertHasNoFormErrors();
});
