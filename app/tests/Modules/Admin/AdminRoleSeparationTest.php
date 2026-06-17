<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * R-17 separation of duties: `admin` is a super-admin (Gate::before bypass);
 * the scoped roles carry only their own permission — admin-finance can't freeze,
 * admin-compliance can't record payments, admin-operations can do neither.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function arsUser(string $role): User
{
    $user = User::create([
        'full_name' => 'Role '.$role,
        'email' => 'ars-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('R17-perm: each scoped role holds only its own permission', function (): void {
    $ops = arsUser('admin-operations');
    $fin = arsUser('admin-finance');
    $comp = arsUser('admin-compliance');
    $super = arsUser('admin');

    expect($ops->can('placement.decide'))->toBeTrue();
    expect($ops->can('finance.record'))->toBeFalse();
    expect($ops->can('compliance.discipline'))->toBeFalse();

    expect($fin->can('finance.record'))->toBeTrue();
    expect($fin->can('compliance.discipline'))->toBeFalse();
    expect($fin->can('placement.decide'))->toBeFalse();

    expect($comp->can('compliance.discipline'))->toBeTrue();
    expect($comp->can('finance.record'))->toBeFalse();
    expect($comp->can('placement.decide'))->toBeFalse();

    // Super-admin bypasses everything via Gate::before.
    expect($super->can('placement.decide'))->toBeTrue();
    expect($super->can('finance.record'))->toBeTrue();
    expect($super->can('compliance.discipline'))->toBeTrue();
});

it('R17-http: admin-finance is forbidden from the block (compliance) action', function (): void {
    $this->actingAs(arsUser('admin-finance'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.freeze', 1), ['reason' => 'x'])
        ->assertForbidden();
});

it('R17-http: admin-operations is forbidden from the block action', function (): void {
    $this->actingAs(arsUser('admin-operations'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.freeze', 1), ['reason' => 'x'])
        ->assertForbidden();
});

it('R17-http: admin-compliance passes the block gate (not forbidden)', function (): void {
    // 999999 doesn't exist → the controller 404/redirects, but crucially it is
    // NOT a 403: the permission gate let admin-compliance through.
    $status = $this->actingAs(arsUser('admin-compliance'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.freeze', 999999), ['reason' => 'x'])
        ->status();

    expect($status)->not->toBe(403);
});

it('R17-http: a non-admin-family user cannot reach the admin area at all', function (): void {
    $user = User::create([
        'full_name' => 'Plain', 'email' => 'ars-plain-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999), 'password_hash' => bcrypt('x'),
        'password_set_at' => now(), 'status' => 'active', 'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
});
