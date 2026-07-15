<?php

use App\Enums\AnalysisPriority;
use App\Enums\ImportRowDisposition;
use App\Enums\ImportStatus;
use App\Models\Company;
use App\Models\CompanyAiAnalysis;
use App\Models\CompanyManualAnalysis;
use App\Models\Contact;
use App\Models\Import;
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

    Gate::before(fn () => true); // isolate render from Shield authz (see RbacTest)
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
    '/admin/imports',
    '/admin/campaigns',
]);

it('renders a company detail page with relation managers, timeline, and AI analysis', function () {
    $company = Company::factory()->create();
    Contact::factory()->for($company)->create();
    Note::factory()->for($company)->create();
    Task::factory()->for($company)->create();
    CompanyManualAnalysis::factory()->for($company)->create(['priority' => AnalysisPriority::High]);
    CompanyAiAnalysis::factory()->for($company)->create(['inferred_priority' => AnalysisPriority::Low]);

    get("/admin/companies/{$company->getKey()}")->assertOk();
    get("/admin/companies/{$company->getKey()}/edit")->assertOk();
});

it('renders an import preview page with staged rows', function () {
    $import = Import::factory()->create(['status' => ImportStatus::Previewed, 'create_count' => 1]);
    $import->rows()->create([
        'row_number' => 1,
        'raw' => ['name' => 'Acme BV'],
        'mapped' => ['name' => 'Acme BV'],
        'disposition' => ImportRowDisposition::Create,
    ]);

    get("/admin/imports/{$import->getKey()}")->assertOk();
});

it('renders a prompt-rule-set detail page with its rules', function () {
    $set = PromptRuleSet::factory()->create(['version' => 1]);
    PromptRule::factory()->for($set, 'set')->create();

    get("/admin/prompt-rule-sets/{$set->getKey()}")->assertOk();
});
