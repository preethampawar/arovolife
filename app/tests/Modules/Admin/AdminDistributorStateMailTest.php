<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\AccountFrozenNotification;
use App\Modules\Identity\Notifications\AccountUnfrozenNotification;
use App\Modules\Identity\Notifications\DistributorDeactivatedNotification;
use App\Modules\Identity\Notifications\DistributorReactivatedNotification;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Proves the four admin account-state actions on AdminDistributorController —
 * freeze, unfreeze, deactivate, reactivate — each notify the affected
 * distributor by email, mirroring the existing terminate pattern.
 */
function asmSeedDistributor(string $userStatus = 'active', string $distStatus = 'active'): array
{
    $user = User::create([
        'email' => 'd'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Test Distributor',
        'date_of_birth' => '1990-01-15',
        'status' => $userStatus,
        'activated_at' => now(),
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => 'AAAA',
            'pan_encrypted' => Crypt::encryptString('ABCDE1234F'),
            'aadhaar_ref' => 'STUB_REF_'.uniqid(),
            'aadhaar_last4' => '9012',
            'aadhaar_encrypted' => Crypt::encryptString('123456789012'),
            'bank_account_enc' => Crypt::encryptString('111122223333'),
            'bank_ifsc' => 'SBIN0000001',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'status' => $distStatus,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id' => $id, 'placement_parent_id' => $id,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);

    return ['user' => $user, 'distributor_id' => $id];
}

function asmAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'email' => 'admin-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('ASM-01: freeze emails the distributor', function (): void {
    Notification::fake();
    ['user' => $user, 'distributor_id' => $id] = asmSeedDistributor('active');
    $admin = asmAdmin();

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.freeze', $id), ['reason' => 'Suspected fraud'])
        ->assertRedirect();

    Notification::assertSentTo($user, AccountFrozenNotification::class, function ($n) {
        return $n->reason === 'Suspected fraud';
    });
});

it('ASM-02: unfreeze emails the distributor', function (): void {
    Notification::fake();
    ['user' => $user, 'distributor_id' => $id] = asmSeedDistributor('frozen');
    $admin = asmAdmin();

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.unfreeze', $id))
        ->assertRedirect();

    Notification::assertSentTo($user, AccountUnfrozenNotification::class);
});

it('ASM-03: deactivate emails the distributor', function (): void {
    Notification::fake();
    ['user' => $user, 'distributor_id' => $id] = asmSeedDistributor('active', 'active');
    $admin = asmAdmin();

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.deactivate', $id))
        ->assertRedirect();

    Notification::assertSentTo($user, DistributorDeactivatedNotification::class);
    Notification::assertNotSentTo($user, DistributorReactivatedNotification::class);
});

it('ASM-04: reactivate emails the distributor', function (): void {
    Notification::fake();
    ['user' => $user, 'distributor_id' => $id] = asmSeedDistributor('active', 'inactive');
    $admin = asmAdmin();

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.activate', $id))
        ->assertRedirect();

    Notification::assertSentTo($user, DistributorReactivatedNotification::class);
    Notification::assertNotSentTo($user, DistributorDeactivatedNotification::class);
});

it('ASM-05: a no-op toggle (already active) sends no mail', function (): void {
    Notification::fake();
    ['user' => $user, 'distributor_id' => $id] = asmSeedDistributor('active', 'active');
    $admin = asmAdmin();

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.activate', $id))
        ->assertRedirect();

    Notification::assertNothingSent();
});
