<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $admin = User::updateOrCreate(
            ['email' => 'admin@arovolife.test'],
            [
                'full_name' => 'Test Admin',
                'phone_e164' => '+919999999999',
                'password_hash' => Hash::make('admin12345'),
                // LoginController gates on password_set_at !== null (used to
                // lock invited spouse accounts that haven't activated yet).
                // Without this, the seeded admin can't log in.
                'password_set_at' => now(),
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );

        $admin->syncRoles([$role]);

        $this->command->info('Admin seeded: admin@arovolife.test / admin12345');
    }
}
