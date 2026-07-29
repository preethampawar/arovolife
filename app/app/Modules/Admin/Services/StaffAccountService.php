<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Provisioning + role management for platform staff accounts (the team
 * running the platform, as opposed to distributors).
 *
 * Single source of truth for both entry points — the admin Staff-users UI
 * and the `staff:create` console command — so the two can never drift on
 * what a valid staff account looks like.
 *
 * Passwords are hashed here and never logged, echoed, or written to an audit
 * row; only `users.password_hash` ever holds the secret.
 */
final class StaffAccountService
{
    /** Roles that may be held by a staff account. */
    public const ASSIGNABLE_ROLES = User::STAFF_ROLES;

    /**
     * Create a staff account, or promote an existing non-distributor user.
     *
     * @param  list<string>  $roles
     *
     * @throws RuntimeException when the email belongs to a distributor
     */
    public function create(
        string $email,
        string $fullName,
        string $phoneE164,
        string $plainPassword,
        array $roles,
        ?int $actorId = null,
    ): User {
        $email = mb_strtolower(trim($email));
        $roles = $this->assertAssignable($roles);

        return DB::transaction(function () use ($email, $fullName, $phoneE164, $plainPassword, $roles, $actorId): User {
            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null) {
                // A staff login and a distributor account must never share a
                // user row: the login channel policy, the admin redirect and
                // the genealogy all key off this distinction.
                if ($existing->distributor()->exists()) {
                    throw new RuntimeException('That email belongs to a distributor account and cannot be made staff.');
                }

                $existing->forceFill([
                    'full_name' => $fullName,
                    'phone_e164' => $phoneE164,
                    'password_hash' => Hash::make($plainPassword),
                    'password_set_at' => now(),
                    'status' => 'active',
                    'email_verified_at' => $existing->email_verified_at ?? now(),
                ])->save();

                $existing->syncRoles($roles);
                $this->audit('staff.user.created', $existing, ['roles' => $roles, 'existing_user' => true], $actorId);

                return $existing;
            }

            $user = User::create([
                'full_name' => $fullName,
                'email' => $email,
                'phone_e164' => $phoneE164,
                'password_hash' => Hash::make($plainPassword),
                // Without this the login controller refuses the account — it
                // treats a null password_set_at as "password never set".
                'password_set_at' => now(),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $user->syncRoles($roles);
            $this->audit('staff.user.created', $user, ['roles' => $roles, 'existing_user' => false], $actorId);

            return $user;
        });
    }

    /**
     * Replace a staff member's roles.
     *
     * @param  list<string>  $roles
     */
    public function syncRoles(User $staff, array $roles, ?int $actorId = null): void
    {
        $roles = $this->assertAssignable($roles);
        $before = $staff->roles->pluck('name')->sort()->values()->all();

        $staff->syncRoles($roles);

        $this->audit('staff.roles.changed', $staff, [
            'from' => $before,
            'to' => array_values($roles),
        ], $actorId);
    }

    /** Freeze or reactivate a staff login. */
    public function setStatus(User $staff, string $status, ?int $actorId = null): void
    {
        if (! in_array($status, ['active', 'frozen'], true)) {
            throw new RuntimeException('Unsupported staff status: '.$status);
        }

        $before = (string) $staff->status;
        $staff->forceFill(['status' => $status])->save();

        $this->audit('staff.status.changed', $staff, ['from' => $before, 'to' => $status], $actorId);
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function assertAssignable(array $roles): array
    {
        $roles = array_values(array_unique(array_filter($roles)));

        if ($roles === []) {
            throw new RuntimeException('A staff account must hold at least one role.');
        }

        foreach ($roles as $role) {
            if (! in_array($role, self::ASSIGNABLE_ROLES, true)) {
                throw new RuntimeException('Unknown staff role: '.$role);
            }
        }

        return $roles;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(string $action, User $staff, array $details, ?int $actorId): void
    {
        AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => 'user',
            'subject_id' => $staff->id,
            // Email identifies the row for compliance; the password never
            // appears here in any form.
            'details' => $details + ['email' => $staff->email],
        ]);
    }
}
