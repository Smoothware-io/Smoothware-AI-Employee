<?php

use App\Enums\CampaignStatus;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Mount the pages and PRESS the buttons.
 *
 * Rendering proves nothing about clicking — that gap has now shipped two 500s to
 * production, and start/pause is the worst possible place for a third: a button
 * that fails silently either does not dial when a client expects it to, or keeps
 * dialling when they press pause.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    actingAs($admin);
    Gate::before(fn () => true);

    config([
        'outbound.enabled' => true,
        'outbound.disclosure' => 'Je spreekt met een AI-assistent.',
        'outbound.register_screening' => 'tps',
        'outbound.openai.project_id' => 'proj_test',
        'outbound.openai.key' => 'sk-test',
        'outbound.allow_any_number' => true,
        'outbound.test_numbers' => [],
    ]);

    $this->campaign = Campaign::create([
        'name' => 'Dutch web agencies',
        'status' => CampaignStatus::Draft,
        'calls_per_hour' => 6,
        'max_call_minutes' => 3,
        'max_attempts' => 2,
        'retry_after_hours' => 24,
        'respect_working_hours' => true,
        'created_by' => $admin->getKey(),
    ]);
});

it('lists campaigns with their status', function () {
    Livewire::test(ListCampaigns::class)
        ->assertOk()
        ->assertSee('Dutch web agencies');
});

it('opens the campaign page with its settings and progress', function () {
    Company::factory()->create([
        'campaign_id' => $this->campaign->getKey(),
        'phone' => '+31611111111',
    ]);

    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getKey()])
        ->assertOk()
        ->assertSee('How it calls')
        ->assertSee('Progress')
        ->assertFormSet(['calls_per_hour' => 6, 'max_call_minutes' => 3]);
});

it('starts calling when the button is pressed', function () {
    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getKey()])
        ->callAction(TestAction::make('start'));

    expect($this->campaign->fresh()->status)->toBe(CampaignStatus::Running)
        ->and($this->campaign->fresh()->started_at)->not->toBeNull();
});

it('refuses to start, and says why, when calling is switched off', function () {
    // Better than a campaign that starts and silently never dials — the exact
    // failure that cost three rounds of log-reading on the voice gateway.
    config(['outbound.enabled' => false]);

    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getKey()])
        ->callAction(TestAction::make('start'));

    expect($this->campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('pauses without losing progress', function () {
    $this->campaign->forceFill(['status' => CampaignStatus::Running, 'started_at' => now()])->save();

    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getKey()])
        ->callAction(TestAction::make('pause'));

    expect($this->campaign->fresh()->status)->toBe(CampaignStatus::Paused)
        // Pausing is not starting over.
        ->and($this->campaign->fresh()->started_at)->not->toBeNull();
});

it('saves a changed pace', function () {
    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getKey()])
        ->fillForm(['calls_per_hour' => 2, 'max_call_minutes' => 5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->campaign->fresh()->calls_per_hour)->toBe(2)
        ->and($this->campaign->fresh()->max_call_minutes)->toBe(5);
});
