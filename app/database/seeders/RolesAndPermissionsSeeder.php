<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin separation of duties (R-17). Strictly additive (firstOrCreate +
 * givePermissionTo) — safe to run on a long-lived environment.
 *
 * - `admin` is the business super-admin (bypasses every permission via
 *   Gate::before in AppServiceProvider). It is granted every permission here
 *   too, so the model still works if that bypass is ever removed.
 * - `developer` supersets admin: same Gate::before bypass plus surfaces gated
 *   by `role:developer` middleware (feature flags, plan-settings edits,
 *   developer-owned settings keys). The role is deliberately never surfaced
 *   to non-developer viewers anywhere in the UI.
 * - The three scoped roles carry ONLY their own permission, enforcing
 *   "admin-finance can't freeze, admin-compliance can't record payments".
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * permission => the scoped role that should hold it.
     *
     * @var array<string, string>
     */
    private const SCOPED = [
        'placement.decide' => 'admin-operations',   // approve/reject line-change
        'finance.record' => 'admin-finance',        // record refunds and finance actions
        'compliance.discipline' => 'admin-compliance', // freeze / unfreeze / terminate
    ];

    /**
     * permission => the scoped roles that should hold it.
     *
     * Separate from SCOPED because these are shared across more than one
     * scoped role. `grievance.handle` deliberately excludes `admin-finance`:
     * grievances routinely name a member of staff, and the finance role has no
     * business reading an ethics complaint about itself.
     *
     * @var array<string, array<int, string>>
     */
    private const SHARED = [
        'grievance.handle' => ['admin-operations', 'admin-compliance'],
    ];

    public function run(): void
    {
        $guard = 'web';

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => $guard]);
        $developer = Role::firstOrCreate(['name' => 'developer', 'guard_name' => $guard]);

        foreach (self::SCOPED as $permissionName => $roleName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => $guard]);
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);

            $role->givePermissionTo($permission);
            $admin->givePermissionTo($permission);
            $developer->givePermissionTo($permission);
        }

        foreach (self::SHARED as $permissionName => $roleNames) {
            $permission = Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => $guard]);

            foreach ($roleNames as $roleName) {
                Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard])->givePermissionTo($permission);
            }

            $admin->givePermissionTo($permission);
            $developer->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
