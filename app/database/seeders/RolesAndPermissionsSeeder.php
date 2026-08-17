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

        // Added 2026-08-17 after the T-6.1 audit found R-17 was enforced on
        // only four routes. Everything else in the admin console was open to
        // every admin-family role, which meant admin-finance — the role that
        // by design cannot freeze an account — could approve a KYC submission,
        // read Aadhaar and PAN scans, and set an arbitrary password on any
        // distributor. That last one is account takeover by a finance clerk,
        // against a staff login with no MFA.
        'kyc.review' => 'admin-operations',         // approve / reject / terminate a KYC submission
        'commerce.order.manage' => 'admin-operations', // ship / deliver / cancel an order
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

        // Reading the audit log is monitoring, not action, and every scoped
        // role needs it to do its own job — but admin-finance reading it is
        // also the check on admin-finance, so it stays broad deliberately.
        'audit.read' => ['admin-operations', 'admin-finance', 'admin-compliance'],
    ];

    /**
     * Permissions no scoped role holds: `admin` and `developer` only.
     *
     * These are the ones where the blast radius is the whole platform rather
     * than one queue. Changing a distributor's credentials or identity is
     * indistinguishable from being them; changing a setting silently changes
     * what the platform pays or who it lets in.
     *
     * @var array<int, string>
     */
    private const SUPER_ONLY = [
        'distributor.credentials',  // set-password, password-reset, identity, id-photo
        'settings.write',           // any settings mutation, including the age rules
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

        foreach (self::SUPER_ONLY as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => $guard]);

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
