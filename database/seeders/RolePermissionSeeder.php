<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The access-control matrix for the whole app — what a sales_rep and a
 * sales_manager may do with each of the 15 Shield entities.
 *
 * WHY A SEEDER AND NOT POLICIES: Filament Shield GENERATES the policies
 * (`shield:generate --all`), so any role logic written into a policy file is
 * silently overwritten the next time a resource is added — a security regression
 * with no failing test behind it. Permission ASSIGNMENT survives regeneration,
 * so the matrix is durable here. The policies stay as Shield writes them: pure
 * `$user->can('Verb:Entity')` checks.
 *
 * This is the executable source of truth; ARCHITECTURE.md §10 explains the
 * reasoning. Tests iterate {@see MATRIX} directly, so the two cannot drift
 * silently: adding an entity without deciding its access fails the suite.
 *
 * super_admin appears nowhere below on purpose — Shield grants it everything.
 */
class RolePermissionSeeder extends Seeder
{
    // --- Bundles -----------------------------------------------------------

    public const READ = ['ViewAny', 'View'];

    public const WRITE = ['Create', 'Update'];

    /** Archive = soft delete (`archived_at`) + undo. Recoverable. */
    public const ARCHIVE = ['Delete', 'DeleteAny', 'Restore', 'RestoreAny'];

    /**
     * Irreversible. super_admin ONLY, for every entity, no exceptions —
     * `archived_at` is the convention, and true erasure runs through dedicated
     * GDPR services (CallContentEraser), never a UI button.
     */
    public const DESTROY = ['ForceDelete', 'ForceDeleteAny'];

    /** Nothing in the product uses these. */
    public const UNUSED = ['Replicate', 'Reorder'];

    /** Every permission Shield generates per entity. */
    public const ALL_VERBS = [...self::READ, ...self::WRITE, ...self::ARCHIVE, ...self::DESTROY, ...self::UNUSED];

    // --- The matrix --------------------------------------------------------

    /**
     * entity => role => list of bundle names (READ/WRITE/ARCHIVE) and/or bare
     * verbs (e.g. 'Update') for the cases a bundle doesn't express.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    public const MATRIX = [
        // --- Daily CRM: the rep's actual job -------------------------------
        // Reps add and work prospects. Removing a company/contact/call from view
        // is a manager call — these are records of substance.
        'Company' => [
            'sales_rep' => ['READ', 'WRITE'],
            'sales_manager' => ['READ', 'WRITE', 'ARCHIVE'],
        ],
        'Contact' => [
            'sales_rep' => ['READ', 'WRITE'],
            'sales_manager' => ['READ', 'WRITE', 'ARCHIVE'],
        ],
        'Call' => [
            'sales_rep' => ['READ', 'WRITE'],
            'sales_manager' => ['READ', 'WRITE', 'ARCHIVE'],
        ],

        // A rep's own workflow items — they may clean up their own mess.
        'Note' => [
            'sales_rep' => ['READ', 'WRITE', 'ARCHIVE'],
            'sales_manager' => ['READ', 'WRITE', 'ARCHIVE'],
        ],
        'Task' => [
            'sales_rep' => ['READ', 'WRITE', 'ARCHIVE'],
            'sales_manager' => ['READ', 'WRITE', 'ARCHIVE'],
        ],
        'Appointment' => [
            'sales_rep' => ['READ', 'WRITE', 'ARCHIVE'],
            'sales_manager' => ['READ', 'WRITE', 'ARCHIVE'],
        ],

        // --- AI surfaces ---------------------------------------------------
        // NOBODY gets Create: the AI authors these. `Update` IS approve/reject,
        // which is the rep's core Phase 3 job — so the rep must have it.
        'AiAction' => [
            'sales_rep' => ['READ', 'Update'],
            'sales_manager' => ['READ', 'Update', 'ARCHIVE'],
        ],
        // An ops log, written by the system. Nobody authors it by hand.
        'AiRun' => [
            'sales_rep' => ['READ'],
            'sales_manager' => ['READ'],
        ],

        // --- Things that change AI behaviour for EVERYONE ------------------
        // Editing the KB or the prompt rules re-aims the AI for the whole team.
        // That is not an individual-contributor act.
        'KnowledgeEntry' => [
            'sales_rep' => ['READ'],
            'sales_manager' => ['READ', 'WRITE', 'ARCHIVE'],
        ],
        'PromptRuleSet' => [
            'sales_rep' => ['READ'],
            'sales_manager' => ['READ', 'WRITE', 'ARCHIVE'],
        ],

        // --- Automation ----------------------------------------------------
        // A standing rule creates work for other people without their
        // per-instance consent: a manager-level decision.
        'FollowUpRule' => [
            'sales_rep' => ['READ'],
            'sales_manager' => ['READ', 'WRITE', 'ARCHIVE'],
        ],
        // The ledger is recorded by the automation, never authored.
        'FollowUp' => [
            'sales_rep' => ['READ'],
            'sales_manager' => ['READ'],
        ],

        // --- Import & campaigns --------------------------------------------
        'Campaign' => [
            'sales_rep' => ['READ'],
            'sales_manager' => ['READ', 'WRITE', 'ARCHIVE'],
        ],
        // Create is manager-only: the upload form requires asserting a GDPR
        // lawful basis, which is a legal determination, not data entry.
        // ARCHIVE is withheld from managers too — an import row IS the Art. 14 /
        // lawful-basis audit trail (GO-LIVE-LEGAL item #2). Compliance evidence
        // must not quietly disappear from view.
        'Import' => [
            'sales_rep' => ['READ'],
            'sales_manager' => ['READ', 'WRITE'],
        ],

        // --- Do-not-contact --------------------------------------------------
        // A rep MUST be able to record an objection the second they are told —
        // any friction here means someone gets called again, so Create is not a
        // manager-level act. But no ARCHIVE and no Update for reps: releasing a
        // suppression starts contact again, which is the consequential direction
        // and belongs with a manager (and leaves a trail via release()).
        'Suppression' => [
            'sales_rep' => ['READ', 'Create'],
            'sales_manager' => ['READ', 'Create', 'Update'],
        ],

        // --- Role management ------------------------------------------------
        // super_admin only. This one is load-bearing: a manager who could grant
        // permissions could grant themselves anything, which would make every
        // row above decorative.
        'Role' => [
            'sales_rep' => [],
            'sales_manager' => [],
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ensurePermissionsExist();

        // One authoritative sync per role. syncPermissions() revokes anything the
        // matrix no longer grants — the seeder must not be additive, or a removed
        // permission would linger forever.
        foreach ($this->permissionsByRole() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Bulk-insert the 180 permission rows; one query rather than 180. */
    private function ensurePermissionsExist(): void
    {
        $all = [];

        foreach (array_keys(self::MATRIX) as $entity) {
            foreach (self::ALL_VERBS as $verb) {
                $all[] = "{$verb}:{$entity}";
            }
        }

        $missing = array_diff($all, Permission::whereIn('name', $all)->pluck('name')->all());

        if ($missing === []) {
            return;
        }

        $now = now();
        Permission::insert(array_map(
            fn (string $name): array => [
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            array_values($missing),
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Flatten the matrix into role => every permission it grants.
     *
     * @return array<string, array<int, string>>
     */
    private function permissionsByRole(): array
    {
        $byRole = [];

        foreach (self::MATRIX as $entity => $roles) {
            foreach ($roles as $roleName => $tokens) {
                $byRole[$roleName] = [
                    ...($byRole[$roleName] ?? []),
                    ...self::permissionsFor($entity, $tokens),
                ];
            }
        }

        return $byRole;
    }

    /**
     * Expand a matrix cell into concrete permission names. Tokens are either a
     * bundle name (READ/WRITE/ARCHIVE) or a bare verb ('Update').
     *
     * @param  array<int, string>  $tokens
     * @return array<int, string>
     */
    public static function permissionsFor(string $entity, array $tokens): array
    {
        $verbs = [];

        foreach ($tokens as $token) {
            $verbs = [...$verbs, ...match ($token) {
                'READ' => self::READ,
                'WRITE' => self::WRITE,
                'ARCHIVE' => self::ARCHIVE,
                'DESTROY' => self::DESTROY,   // never used in MATRIX; guarded by test
                default => [$token],
            }];
        }

        return array_values(array_unique(
            array_map(fn (string $verb): string => "{$verb}:{$entity}", $verbs)
        ));
    }
}
