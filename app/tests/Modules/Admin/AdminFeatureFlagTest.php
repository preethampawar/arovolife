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

/**
 * Feature flags decide whether whole compensation engines run, so the page is
 * gated to the platform-configuration role — a business `admin` is refused
 * (see FF-ADMIN-07).
 */
function adminFlagSeedStaff(string $role = 'developer'): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $staff = User::create([
        'full_name' => 'Flag Test Staff',
        'email' => 'staff-flag-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $staff->assignRole($role);

    return $staff;
}

it('FF-ADMIN-01: index page lists the registration killswitch', function (): void {
    $admin = adminFlagSeedStaff();
    $this->actingAs($admin);

    $response = $this->get('/admin/feature-flags');

    $response->assertStatus(200);
    $response->assertSee('registration.killswitch');
    $response->assertSee('Registration killswitch');
});

it('FF-ADMIN-02: admin can deactivate the flag and an audit row is written', function (): void {
    $admin = adminFlagSeedStaff();
    $this->actingAs($admin);
    // Read against the global (null) scope — the toggle endpoint writes there
    // intentionally so the flag affects unauthenticated visitors too. Reading
    // with the default scope returns the admin's own override, which is what
    // the old test was checking and that the production fix moved away from.
    expect(Feature::for(null)->active(RegistrationKillswitch::class))->toBeTrue();

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/feature-flags/registration.killswitch', ['action' => 'deactivate']);

    $response->assertRedirect();
    expect(Feature::for(null)->active(RegistrationKillswitch::class))->toBeFalse();
    expect(AuditLog::where('action', 'feature_flag.toggled')->count())->toBe(1);

    $row = AuditLog::where('action', 'feature_flag.toggled')->first();
    expect($row->actor_id)->toBe($admin->id);
    expect($row->details['flag'])->toBe('registration.killswitch');
    expect($row->details['from'])->toBeTrue();
    expect($row->details['to'])->toBeFalse();
});

it('FF-ADMIN-03: admin can re-activate a deactivated flag', function (): void {
    $admin = adminFlagSeedStaff();
    $this->actingAs($admin);
    Feature::for(null)->deactivate(RegistrationKillswitch::class);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/feature-flags/registration.killswitch', ['action' => 'activate']);

    $response->assertRedirect();
    expect(Feature::for(null)->active(RegistrationKillswitch::class))->toBeTrue();
});

it('FF-ADMIN-04: unknown flag key returns 404', function (): void {
    $admin = adminFlagSeedStaff();
    $this->actingAs($admin);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/feature-flags/does.not.exist', ['action' => 'activate']);

    $response->assertStatus(404);
});

it('FF-ADMIN-05: invalid action value returns 422', function (): void {
    $admin = adminFlagSeedStaff();
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

it('FF-ADMIN-07: a business admin sees and can pull the registration killswitch', function (): void {
    // Incident controls must not depend on one person being available: any
    // console role can halt registrations during a compliance incident.
    $this->actingAs(adminFlagSeedStaff('admin'));

    $this->get('/admin/feature-flags')
        ->assertStatus(200)
        ->assertSee('registration.killswitch');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/feature-flags/registration.killswitch', ['action' => 'deactivate'])
        ->assertRedirect();

    expect(Feature::for(null)->active(RegistrationKillswitch::class))->toBeFalse();
    expect(AuditLog::where('action', 'feature_flag.toggled')->count())->toBe(1);
});

it('FF-ADMIN-07b: compliance staff can also pull the killswitch', function (): void {
    $this->actingAs(adminFlagSeedStaff('admin-compliance'));

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/feature-flags/registration.killswitch', ['action' => 'deactivate'])
        ->assertRedirect();

    expect(Feature::for(null)->active(RegistrationKillswitch::class))->toBeFalse();
});

it('FF-ADMIN-07c: engine flags stay with platform configuration', function (): void {
    // Compensation engines decide what distributors are paid, so they are not
    // toggleable from the business console — 404, matching an unknown key, so
    // probing cannot confirm the flag exists.
    $this->actingAs(adminFlagSeedStaff('admin'));

    $this->get('/admin/feature-flags')
        ->assertStatus(200)
        ->assertDontSee('compensation.genos_sales_bonus');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/feature-flags/compensation.genos_sales_bonus', ['action' => 'activate'])
        ->assertStatus(404);

    expect(AuditLog::where('action', 'feature_flag.toggled')->count())->toBe(0);
});

it('FF-ADMIN-08: the GSB daily-pool flag is registered with its compliance gate', function (): void {
    $this->actingAs(adminFlagSeedStaff());

    $response = $this->get('/admin/feature-flags');

    $response->assertStatus(200);
    $response->assertSee('compensation.gsb_daily_pool_pricing');
    // R-33: the page must carry the do-not-enable-yet warning.
    $response->assertSee('DSA §6.2', false);
});
