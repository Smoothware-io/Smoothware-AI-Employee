<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\Note;
use App\Models\PromptRule;
use App\Models\PromptRuleSet;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    actingAs($admin);

    // This suite verifies the Filament resource pages COMPILE and RENDER.
    // Shield authorization (super_admin => all generated permissions) is
    // exercised in RbacTest and verified against real Postgres; here we bypass it
    // so a render regression can't hide behind a permissions failure.
    Gate::before(fn () => true);
});

it('renders every resource index page', function (string $url) {
    get($url)->assertOk();
})->with([
    '/admin/companies',
    '/admin/contacts',
    '/admin/notes',
    '/admin/tasks',
    '/admin/appointments',
    '/admin/calls',
    '/admin/knowledge-entries',
    '/admin/prompt-rule-sets',
    '/admin/ai-actions',
    '/admin/ai-runs',
]);

it('renders a company detail page with its relation managers and timeline', function () {
    $company = Company::factory()->create();
    Contact::factory()->for($company)->create();
    Note::factory()->for($company)->create();
    Task::factory()->for($company)->create();

    get("/admin/companies/{$company->getKey()}")->assertOk();
});

it('renders a prompt-rule-set detail page with its rules', function () {
    $set = PromptRuleSet::factory()->create(['version' => 1]);
    PromptRule::factory()->for($set, 'set')->create();

    get("/admin/prompt-rule-sets/{$set->getKey()}")->assertOk();
});
