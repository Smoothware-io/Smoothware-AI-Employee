<?php

use App\Filament\Resources\Calls\Pages\ViewCall;
use App\Models\Call;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Renders the page, not just the parser.
 *
 * The first version of the transcript view shipped with a green suite and 500'd
 * on the real page: the unit tests covered TranscriptParser while the Blade file
 * referenced `$record`, which Filament does not put in scope. A parser test can
 * never catch that — only mounting the page can.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    actingAs($admin);

    Gate::before(fn () => true); // isolate render from authz (see RbacTest)
});

it('renders a call transcript as a conversation', function () {
    $call = Call::create([
        'direction' => 'inbound',
        'status' => 'completed',
        'started_at' => now(),
        'transcript' => "CALLER: Kan ik een afspraak maken?\nAI: Zeker, morgen om twee uur?",
        'transcript_status' => 'done',
    ]);

    Livewire::test(ViewCall::class, ['record' => $call->getKey()])
        ->assertOk()
        ->assertSee('Kan ik een afspraak maken?')
        ->assertSee('Zeker, morgen om twee uur?');
});

it('renders a call that has no transcript without erroring', function () {
    $call = Call::create([
        'direction' => 'outbound',
        'status' => 'completed',
        'started_at' => now(),
    ]);

    Livewire::test(ViewCall::class, ['record' => $call->getKey()])
        ->assertOk()
        ->assertSee('No transcript yet');
});

it('says content was erased rather than rendering an empty conversation', function () {
    // A reviewer must be able to tell a GDPR erasure from a broken pipeline.
    $call = Call::create([
        'direction' => 'inbound',
        'status' => 'completed',
        'started_at' => now(),
        'content_erased_at' => now(),
    ]);

    Livewire::test(ViewCall::class, ['record' => $call->getKey()])
        ->assertOk()
        ->assertSee('erased');
});
