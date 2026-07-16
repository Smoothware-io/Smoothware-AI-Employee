<?php

use App\Filament\Widgets\AiTrustPanel;
use App\Filament\Widgets\ComplianceGauges;
use App\Filament\Widgets\PipelineOverview;
use App\Filament\Widgets\ProviderStatusBanner;
use App\Models\AiRun;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Asserts the dashboard widgets actually render the words a human would look for.
 *
 * Filament widgets are LAZY Livewire components: `get('/admin')` returns 200 with
 * only placeholders, so an assertOk()-style smoke test proves the page boots and
 * nothing more — a widget could throw on every render and still pass. Each widget
 * is therefore mounted directly, which is the only place its output exists.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    actingAs($admin);

    Gate::before(fn () => true); // isolate render from authz (see RbacTest)
});

it('boots the dashboard page', function () {
    get('/admin')->assertOk();
});

it('renders the demo-data banner naming the fake providers', function () {
    Livewire::test(ProviderStatusBanner::class)
        ->assertSee('no AI providers are connected yet')
        ->assertSee('Do not read them as KPIs')
        // Provider names come from live config, not a hardcoded string.
        ->assertSee('Receptionist LLM')
        ->assertSee('Embeddings');
});

it('hides the banner once every provider is real', function () {
    config([
        'receptionist.drivers.llm' => 'claude',
        'receptionist.drivers.telephony' => 'sonetel',
        'analysis.drivers.llm' => 'claude',
        'analysis.drivers.website' => 'http',
        'services.embeddings.driver' => 'voyage',
    ]);

    expect(ProviderStatusBanner::canView())->toBeFalse();
});

it('shows denominators rather than a confident rate on a tiny sample', function () {
    AiRun::factory()->count(3)->fellBackToHuman()->create();

    Livewire::test(AiTrustPanel::class)
        ->assertSee('AI trust')
        ->assertSee('too few to read as a rate')
        // 3 of 3 fell back, but 3 observations must never print as "100.0%".
        ->assertDontSee('100.0%');
});

it('renders the pipeline widget', function () {
    Livewire::test(PipelineOverview::class)
        ->assertSee('Leads')
        ->assertSee('Overdue tasks');
});

it('renders the compliance gauges including KB staleness', function () {
    Livewire::test(ComplianceGauges::class)
        ->assertSee('Imports with no lawful basis')
        ->assertSee('KB chunks not retrievable');
});
