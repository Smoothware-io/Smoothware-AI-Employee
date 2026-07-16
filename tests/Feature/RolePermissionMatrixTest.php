<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder as Matrix;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * The tripwire for the whole access-control matrix.
 *
 * These tests iterate {@see Matrix::MATRIX} itself rather than restating it, so
 * the doc, the seeder and the tests cannot drift: adding an entity without
 * deciding its access fails here.
 *
 * NON-VACUITY MATTERS. If a policy were missing entirely, every `can()` would
 * return false and a suite of only-negative assertions would pass while proving
 * nothing. Every entity below is therefore asserted in BOTH directions: the
 * granted permissions must be true (which can only happen if the policy is
 * registered AND the permission assigned), and the withheld ones false.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(Matrix::class);
});

function roleUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/** @return array<int, array{0: string, 1: string}> entity/role pairs from the matrix */
function matrixCells(): array
{
    $cells = [];

    foreach (Matrix::MATRIX as $entity => $roles) {
        foreach (array_keys($roles) as $role) {
            $cells[] = [$entity, $role];
        }
    }

    return $cells;
}

it('grants exactly the matrix and nothing more', function (string $entity, string $role) {
    $user = roleUser($role);
    $granted = Matrix::permissionsFor($entity, Matrix::MATRIX[$entity][$role]);

    foreach (Matrix::ALL_VERBS as $verb) {
        $permission = "{$verb}:{$entity}";
        $shouldHave = in_array($permission, $granted, true);

        expect($user->hasPermissionTo($permission))->toBe(
            $shouldHave,
            $shouldHave
                ? "{$role} should have {$permission}"
                : "{$role} must NOT have {$permission}",
        );
    }
})->with(matrixCells());

it('resolves the permissions through a registered policy, not a missing-policy default', function (string $entity) {
    // Proves the policy for this entity actually exists and is wired: a user
    // holding ViewAny can, an identical user without it cannot. Without this,
    // every negative assertion above would be vacuous.
    $permission = "ViewAny:{$entity}";
    Permission::findOrCreate($permission, 'web');

    $model = "App\\Models\\{$entity}";
    $model = $entity === 'Role' ? Role::class : $model;

    $allowed = User::factory()->create();
    $allowed->givePermissionTo($permission);

    $denied = User::factory()->create();

    expect($allowed->fresh()->can('viewAny', $model))->toBeTrue("policy for {$entity} should allow with {$permission}")
        ->and($denied->fresh()->can('viewAny', $model))->toBeFalse("policy for {$entity} should deny without {$permission}");
})->with(array_keys(Matrix::MATRIX));

it('never grants irreversible deletion to anyone but super_admin', function () {
    // archived_at is the convention; true erasure runs through GDPR services,
    // never a UI button. This must hold for EVERY entity.
    foreach (['sales_rep', 'sales_manager'] as $role) {
        $user = roleUser($role);

        foreach (array_keys(Matrix::MATRIX) as $entity) {
            foreach (Matrix::DESTROY as $verb) {
                expect($user->hasPermissionTo("{$verb}:{$entity}"))
                    ->toBeFalse("{$role} must never have {$verb}:{$entity}");
            }
        }
    }
});

it('never lets a manager manage roles', function () {
    // Load-bearing: a manager who could grant permissions could grant themselves
    // anything, making the entire matrix decorative.
    $manager = roleUser('sales_manager');

    foreach (Matrix::ALL_VERBS as $verb) {
        expect($manager->hasPermissionTo("{$verb}:Role"))->toBeFalse("sales_manager must not have {$verb}:Role");
    }
});

it('withholds import authoring from reps and import archiving from everyone', function () {
    $rep = roleUser('sales_rep');
    $manager = roleUser('sales_manager');

    // Creating an import asserts a GDPR lawful basis — a legal determination.
    expect($rep->hasPermissionTo('Create:Import'))->toBeFalse()
        ->and($manager->hasPermissionTo('Create:Import'))->toBeTrue();

    // An import row is the Art. 14 / lawful-basis audit trail; it must not be
    // archivable out of view by anyone below super_admin.
    foreach (Matrix::ARCHIVE as $verb) {
        expect($rep->hasPermissionTo("{$verb}:Import"))->toBeFalse()
            ->and($manager->hasPermissionTo("{$verb}:Import"))->toBeFalse("even a manager must not {$verb} an import");
    }
});

it('lets a rep approve an AI action but never author one', function () {
    $rep = roleUser('sales_rep');

    // Update IS approve/reject — the rep's core Phase 3 job.
    expect($rep->hasPermissionTo('Update:AiAction'))->toBeTrue()
        // The AI authors these; a human writing one by hand would forge provenance.
        ->and($rep->hasPermissionTo('Create:AiAction'))->toBeFalse();
});

it('keeps AI behaviour out of individual-contributor hands', function (string $entity) {
    // Editing the KB or the prompt rules re-aims the AI for the whole team.
    $rep = roleUser('sales_rep');
    $manager = roleUser('sales_manager');

    expect($rep->hasPermissionTo("ViewAny:{$entity}"))->toBeTrue()
        ->and($rep->hasPermissionTo("Update:{$entity}"))->toBeFalse()
        ->and($manager->hasPermissionTo("Update:{$entity}"))->toBeTrue();
})->with(['KnowledgeEntry', 'PromptRuleSet']);

it('revokes permissions the matrix no longer grants when re-seeded', function () {
    // The seeder is authoritative, not additive: a permission removed from the
    // matrix must actually disappear, not linger forever. The matrix governs
    // ROLES, so the drift it has to correct is a stray role grant.
    $role = SpatieRole::findByName('sales_rep', 'web');
    $role->givePermissionTo(Permission::findOrCreate('Delete:Company', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(roleUser('sales_rep')->hasPermissionTo('Delete:Company'))->toBeTrue();

    $this->seed(Matrix::class);

    expect(roleUser('sales_rep')->hasPermissionTo('Delete:Company'))->toBeFalse();
});

it('covers every Shield entity in the matrix', function () {
    // A new resource without an access decision is a hole. Policies are the
    // canonical list of Shield entities.
    $policies = collect(File::files(app_path('Policies')))
        ->map(fn ($f): string => str_replace('Policy', '', $f->getFilenameWithoutExtension()))
        ->sort()
        ->values()
        ->all();

    expect(collect(array_keys(Matrix::MATRIX))->sort()->values()->all())->toBe($policies);
});
