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
 * - `admin` is the super-admin (bypasses every permission via Gate::before in
 *   AppServiceProvider). It is granted every permission here too, so the model
 *   still works if that bypass is ever removed.
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
        'finance.record' => 'admin-finance',        // record COD payment, refunds
        'compliance.discipline' => 'admin-compliance', // freeze / unfreeze / terminate
    ];

    public function run(): void
    {
        $guard = 'web';

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => $guard]);

        foreach (self::SCOPED as $permissionName => $roleName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => $guard]);
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);

            $role->givePermissionTo($permission);
            $admin->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
