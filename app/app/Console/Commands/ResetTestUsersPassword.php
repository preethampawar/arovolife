<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;

final class ResetTestUsersPassword extends Command
{
    protected $signature = 'reset:test-users-password {password : The password to set for all test users}';

    protected $description = 'Reset password for all test/staging users (emails containing "test", "may2026", "mailinator")';

    public function handle(): int
    {
        $password = $this->argument('password');

        // Find test users (emails with test, may2026, mailinator patterns)
        $users = User::where(function ($query) {
            $query->where('email', 'like', '%test%')
                  ->orWhere('email', 'like', '%may2026%')
                  ->orWhere('email', 'like', '%mailinator%');
        })->get();

        if ($users->isEmpty()) {
            $this->info('No test users found.');
            return 0;
        }

        $this->info("Found {$users->count()} test user(s):");

        foreach ($users as $user) {
            $user->update(['password_hash' => bcrypt($password)]);
            $this->line("  ✓ {$user->email}");
        }

        $this->info("\n✓ Password reset successfully for all test users to: {$password}");

        return 0;
    }
}
