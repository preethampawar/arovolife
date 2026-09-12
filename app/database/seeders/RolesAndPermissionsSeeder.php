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

        // Added 2026-09-04 with the Razorpay refunds. Marking a return as
        // received releases the held cooling-off refund — real money — and
        // must never sit with the role that can also settle a refund by hand
        // (`finance.record`): whoever confirms the goods are back is not the
        // person who can then write the payable off or pay it out.
        'returns.receive' => 'admin-operations',    // mark a return received / not returned

        // Added 2026-09-12 with the inventory module. Receiving goods,
        // transferring them between warehouses and adjusting a count all change
        // what the company owns, and an adjustment with no counterparty is the
        // one stock movement nobody else has to agree to — it sits with the
        // role that physically handles the goods, never with finance, which
        // reads the valuation those movements produce.
        'inventory.manage' => 'admin-operations',   // warehouses, suppliers, POs, GRNs, transfers, adjustments
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

        // Reading an Arete Development Centre application exposes the
        // applicant's contact details, premises deed and photos. Finance has
        // no role in siting a centre, so it cannot open the queue at all;
        // the decision itself sits behind `compliance.discipline`.
        'adc.application.review' => ['admin-operations', 'admin-compliance'],

        // Reading a distributor request exposes identity documents and, for
        // a transfer, a relative's details. Operations decides name / DOB
        // corrections (`kyc.review`); compliance decides transfers and
        // cancellations (`compliance.discipline`). Finance has no part.
        'distributor.request.handle' => ['admin-operations', 'admin-compliance'],

        // Reading a reported message means reading a private conversation
        // between two distributors. It sits with the same two roles that
        // handle grievances and for the same reason: the reports that matter
        // are income claims and harassment, which routinely name staff, and
        // finance has no business reading a complaint about itself.
        'messaging.moderate' => ['admin-operations', 'admin-compliance'],

        // Added 2026-09-11 (QA F26). Publishing a content page or an
        // announcement is a company statement to the whole distributor base,
        // and archiving a page takes a statutory disclosure — the Privacy
        // Notice, the Code of Ethics, the T&C — off the public site. Both were
        // open to every admin-family role, admin-finance included, behind
        // nothing but a phrase list. It sits with the two roles that already
        // answer for what the company says (R-17); finance does not hold it.
        'content.publish' => ['admin-operations', 'admin-compliance'],

        // Reading the audit log is monitoring, not action, and every scoped
        // role needs it to do its own job — but admin-finance reading it is
        // also the check on admin-finance, so it stays broad deliberately.
        'audit.read' => ['admin-operations', 'admin-finance', 'admin-compliance'],

        // Stock levels, batch expiry and valuation are read by both the people
        // who hold the goods and the people who account for them. Reading is
        // separated from moving (`inventory.manage`) so finance can reconcile
        // and report without being able to write off a shortage it found.
        'inventory.view' => ['admin-operations', 'admin-finance'],

        // Added 2026-09-12 with the Action Center. The screen itself only
        // aggregates what each viewer can already see: the rows inside it are
        // scoped per provider by that provider's own permission, so this grant
        // opens the page, not the queues on it. All three scoped roles hold it
        // because every one of them has work that lands there.
        'action.center.view' => ['admin-operations', 'admin-finance', 'admin-compliance'],
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

        // Added 2026-09-11 (QA F94). The checker half of maker-checker on
        // payouts. `finance.record` is the maker: it runs a batch, imports the
        // bank's response and retries a failed transfer. Signing a batch off —
        // the decision that releases the money — is deliberately NOT in the
        // same hand, so admin-finance does not hold it and neither does
        // admin-compliance (which has no business in the payment run at all).
        // On top of that, whoever created a batch cannot approve that batch,
        // checked against `payout_batches.created_by` at approval time.
        'finance.approve',          // sign a payout batch off for payment
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
