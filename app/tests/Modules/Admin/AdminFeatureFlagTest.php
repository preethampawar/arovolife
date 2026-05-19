<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\RegistrationKillswitch;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function adminFlagSeedAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'Admin Test',
        'email' => 'admin-flag-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('FF-ADMIN-01: index page lists the registration killswitch', function (): void {
    $admin = adminFlagSeedAdmin();
    $this->actingAs($admin);

    $response = $this->get('/admin/feature-flags');

    $response->assertStatus(200);
    $response->assertSee('registration.killswitch');
    $response->assertSee('Registration killswitch');
});

it('FF-ADMIN-02: admin can deactivate the flag and an audit row is written', function (): void {
    $admin = adminFlagSeedAdmin();
    $this->actingAs($admin);
    expect(Feature::active(RegistrationKillswitch::class))->toBeTrue();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/feature-flags/registration.killswitch', ['action' => 'deactivate']);

    $response->assertRedirect();
    expect(Feature::active(RegistrationKillswitch::class))->toBeFalse();
    expect(AuditLog::where('action', 'feature_flag.toggled')->count())->toBe(1);

    $row = AuditLog::where('action', 'feature_flag.toggled')->first();
    expect($row->actor_id)->toBe($admin->id);
    expect($row->details['flag'])->toBe('registration.killswitch');
    expect($row->details['from'])->toBeTrue();
    expect($row->details['to'])->toBeFalse();
});

it('FF-ADMIN-03: admin can re-activate a deactivated flag', function (): void {
    $admin = adminFlagSeedAdmin();
    $this->actingAs($admin);
    Feature::deactivate(RegistrationKillswitch::class);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/feature-flags/registration.killswitch', ['action' => 'activate']);

    $response->assertRedirect();
    expect(Feature::active(RegistrationKillswitch::class))->toBeTrue();
});

it('FF-ADMIN-04: unknown flag key returns 404', function (): void {
    $admin = adminFlagSeedAdmin();
    $this->actingAs($admin);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/feature-flags/does.not.exist', ['action' => 'activate']);

    $response->assertStatus(404);
});

it('FF-ADMIN-05: invalid action value returns 422', function (): void {
    $admin = adminFlagSeedAdmin();
    $this->actingAs($admin);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/feature-flags/registration.killswitch', ['action' => 'nuke']);

    $response->assertStatus(422);
});

it('FF-ADMIN-06: non-admin cannot reach the feature flags page', function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::create([
        'full_name' => 'Plain user',
        'email' => 'plain-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('xpass'),
        'status' => 'active',
    ]);
    $this->actingAs($user);

    $response = $this->get('/admin/feature-flags');

    expect($response->status())->not->toBe(200);
});
