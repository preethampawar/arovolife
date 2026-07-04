<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function staffSeedAdmin(string $role = 'admin'): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::create([
        'full_name' => 'Staff '.ucfirst($role),
        'email' => 'staff-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('lists only role-holding users, never distributors', function (): void {
    $admin = staffSeedAdmin();
    $ops = staffSeedAdmin('admin-operations');
    $dist = Distributor::factory()->create();

    $response = $this->actingAs($admin)->get(route('admin.staff.index'));

    $response->assertOk()
        ->assertSee($admin->email)
        ->assertSee($ops->email)
        ->assertSee('admin-operations')
        ->assertDontSee($dist->user->email);
});

it('is super-admin only — scoped admin roles get 403', function (): void {
    $ops = staffSeedAdmin('admin-operations');

    $this->actingAs($ops)->get(route('admin.staff.index'))->assertForbidden();
});

it('filters staff by role', function (): void {
    $admin = staffSeedAdmin();
    $finance = staffSeedAdmin('admin-finance');

    $response = $this->actingAs($admin)->get(route('admin.staff.index', ['role' => 'admin-finance']));

    $response->assertOk();
    // Assert on view data — the admin's own email always appears in the
    // layout header, so a page-body assertDontSee would false-negative.
    $emails = $response->viewData('staff')->pluck('email');
    expect($emails)->toContain($finance->email)
        ->not->toContain($admin->email);
});

it('distributor status counts exclude staff users', function (): void {
    $admin = staffSeedAdmin();
    Distributor::factory()->count(2)->create();

    $response = $this->actingAs($admin)->get(route('admin.distributors.index'));

    // 2 distributor users are active; the admin staff user must not be
    // counted in the register's status pills.
    $response->assertOk();
    expect($response->viewData('statusCounts')->get('active'))->toEqual(2);
});
