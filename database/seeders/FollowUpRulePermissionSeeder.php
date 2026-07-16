<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may author follow-up rules (Phase 7).
 *
 * A rule is a STANDING INSTRUCTION that creates work for other people without
 * their per-instance consent — a manager-level decision, not an
 * individual-contributor one. So: sales_manager may write rules; sales_rep may
 * only look at them (and at the resulting ledger).
 *
 * Why this lives in a seeder rather than a policy: Filament Shield GENERATES the
 * policies (`shield:generate --all`), so a hand-written role check inside
 * FollowUpRulePolicy would be silently overwritten on the next regeneration —
 * a security regression with no failing test to catch it. Permission ASSIGNMENT
 * survives regeneration, so the restriction is durable here.
 *
 * super_admin needs nothing from this seeder: Shield grants it everything.
 */
class FollowUpRulePermissionSeeder extends Seeder
{
    /** Writing rules: manager-and-above only. */
    public const MANAGER_ONLY = [
        'Create:FollowUpRule',
        'Update:FollowUpRule',
        'Delete:FollowUpRule',
        'DeleteAny:FollowUpRule',
        'Restore:FollowUpRule',
        'RestoreAny:FollowUpRule',
        'ForceDelete:FollowUpRule',
        'ForceDeleteAny:FollowUpRule',
        'Replicate:FollowUpRule',
        'Reorder:FollowUpRule',
    ];

    /** Reading rules + the resulting ledger: everyone in sales. */
    public const READ_ONLY = [
        'ViewAny:FollowUpRule',
        'View:FollowUpRule',
        'ViewAny:FollowUp',
        'View:FollowUp',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([...self::MANAGER_ONLY, ...self::READ_ONLY] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $manager = Role::findOrCreate('sales_manager', 'web');
        $manager->givePermissionTo([...self::MANAGER_ONLY, ...self::READ_ONLY]);

        // Note the omission: sales_rep never receives MANAGER_ONLY.
        $rep = Role::findOrCreate('sales_rep', 'web');
        $rep->givePermissionTo(self::READ_ONLY);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
