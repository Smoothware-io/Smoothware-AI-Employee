<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\Note;
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
    // exercised in RbacTest and verified against real MySQL; here we bypass it
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
]);

it('renders a company detail page with its relation managers and timeline', function () {
    $company = Company::factory()->create();
    Contact::factory()->for($company)->create();
    Note::factory()->for($company)->create();
    Task::factory()->for($company)->create();

    get("/admin/companies/{$company->getKey()}")->assertOk();
});
