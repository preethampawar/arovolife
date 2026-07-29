<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use App\Modules\Admin\Services\StaffAccountService;
use App\Modules\Identity\Http\Rules\NotPwned;
use App\Modules\Identity\Http\Rules\StrongPassword;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Bootstrap a platform staff account from the console.
 *
 * This exists for the accounts that cannot be created through the admin UI —
 * chiefly the very first staff login on a fresh environment, before anyone
 * can sign in to create others.
 *
 * The password is collected through a hidden interactive prompt, never as a
 * command argument: arguments land in shell history, process lists and CI
 * logs. It is hashed by StaffAccountService and only ever stored as
 * `users.password_hash` — it is not written to config, .env, the repo, or an
 * audit row.
 *
 * Usage (interactive, inside the app container):
 *
 *     php artisan staff:create someone@example.com
 *     php artisan staff:create someone@example.com --role=admin-finance
 */
final class CreateStaffUserCommand extends Command
{
    // --role has no default on purpose: a bare `staff:create x@y.com` must
    // never silently mint the most privileged account in the system.
    protected $signature = 'staff:create
        {email : Sign-in email for the staff account}
        {--role=* : Role to assign (repeatable, required)}';

    protected $description = 'Create a platform staff account with an interactively-entered password';

    public function handle(StaffAccountService $staff): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Not a valid email address: '.$email);

            return self::FAILURE;
        }

        /** @var list<string> $roles */
        $roles = array_values(array_filter((array) $this->option('role')));

        if ($roles === []) {
            $this->error('At least one --role is required.');
            $this->line('Available: '.implode(', ', StaffAccountService::ASSIGNABLE_ROLES));

            return self::FAILURE;
        }

        foreach ($roles as $role) {
            if (! in_array($role, StaffAccountService::ASSIGNABLE_ROLES, true)) {
                $this->error('Unknown role: '.$role);
                $this->line('Available: '.implode(', ', StaffAccountService::ASSIGNABLE_ROLES));

                return self::FAILURE;
            }
        }

        $fullName = text(
            label: 'Full name',
            required: true,
            validate: fn (string $value) => mb_strlen($value) > 150 ? 'Keep the name under 150 characters.' : null,
        );

        $phone = text(
            label: 'Mobile number (E.164)',
            placeholder: '+919876543210',
            required: true,
            validate: fn (string $value) => preg_match('/^\+91[6-9]\d{9}$/', $value) === 1
                ? null
                : 'Enter an Indian mobile as +91XXXXXXXXXX.',
        );

        $plain = password(
            label: 'Password (min 12 characters)',
            required: true,
            // Same strength + breach checks the UI applies — staff hold the
            // highest privileges on the platform.
            validate: function (string $value): ?string {
                if (mb_strlen($value) < 12) {
                    return 'Use at least 12 characters.';
                }

                $errors = validator(
                    ['password' => $value],
                    ['password' => [new StrongPassword, new NotPwned]],
                )->errors();

                return $errors->isEmpty() ? null : (string) $errors->first('password');
            },
        );

        $confirm = password(label: 'Confirm password', required: true);

        if ($plain !== $confirm) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        try {
            $user = $staff->create(
                email: $email,
                fullName: $fullName,
                phoneE164: $phone,
                plainPassword: $plain,
                roles: $roles,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Staff account ready: '.$user->email);
        $this->line('Roles: '.implode(', ', $roles));
        $this->line('Sign in at /login using the EMAIL address (distributors sign in with their ADN).');

        return self::SUCCESS;
    }
}
