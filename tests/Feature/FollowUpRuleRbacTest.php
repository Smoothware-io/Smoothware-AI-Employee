<?php

use App\Models\FollowUp;
use App\Models\FollowUpRule;
use App\Models\User;
use Database\Seeders\FollowUpRulePermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * A follow-up rule is a standing instruction that creates work for other people
 * without their per-instance consent, so authoring it is a manager-level
 * decision. These tests are the tripwire for that: the restriction lives in
 * permission assignment (Shield regenerates policies, so a hand-written role
 * check in the policy would be silently clobbered), and nothing else would catch
 * a regression.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(FollowUpRulePermissionSeeder::class);
});

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

it('lets a sales manager author, edit and delete rules', function () {
    $manager = userWithRole('sales_manager');
    $rule = FollowUpRule::factory()->create();

    expect($manager->can('create', FollowUpRule::class))->toBeTrue()
        ->and($manager->can('update', $rule))->toBeTrue()
        ->and($manager->can('delete', $rule))->toBeTrue()
        ->and($manager->can('viewAny', FollowUpRule::class))->toBeTrue();
});

it('lets a sales rep read rules but never author or change them', function () {
    $rep = userWithRole('sales_rep');
    $rule = FollowUpRule::factory()->create();

    expect($rep->can('viewAny', FollowUpRule::class))->toBeTrue()
        ->and($rep->can('view', $rule))->toBeTrue()
        // The whole point of the restriction:
        ->and($rep->can('create', FollowUpRule::class))->toBeFalse()
        ->and($rep->can('update', $rule))->toBeFalse()
        ->and($rep->can('delete', $rule))->toBeFalse();
});

it('lets both roles read the follow-up ledger', function (string $role) {
    expect(userWithRole($role)->can('viewAny', FollowUp::class))->toBeTrue();
})->with(['sales_manager', 'sales_rep']);

it('never grants any role permission to write the ledger by hand', function (string $role) {
    // The ledger records what the automation decided; it is not authored.
    expect(userWithRole($role)->can('create', FollowUp::class))->toBeFalse();
})->with(['sales_manager', 'sales_rep']);

it('withholds every write permission from sales_rep', function () {
    $rep = userWithRole('sales_rep');

    foreach (FollowUpRulePermissionSeeder::MANAGER_ONLY as $permission) {
        expect($rep->hasPermissionTo($permission))->toBeFalse("sales_rep must not have {$permission}");
    }
});
