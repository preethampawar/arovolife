<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * The `developer` role supersets `admin`: it inherits every business
 * capability and additionally owns platform configuration (feature flags,
 * compensation-plan edits, developer-owned settings keys).
 *
 * It is also STEALTH — a non-developer viewer must not be able to learn that
 * the role, or any account holding it, exists. These tests lock both halves:
 * the capability split and the concealment.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function devStaff(string $role, ?string $email = null): User
{
    $user = User::create([
        'full_name' => ucfirst($role).' Person',
        'email' => $email ?? $role.'-'.uniqid().'@arovolife.test',
        'phone_e164' => '+9199'.rand(10000000, 99999999),
        'password_hash' => bcrypt('CorrectHorseBattery1'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

// ─── Capability split ────────────────────────────────────────────────────────

it('DEV-01: developer reaches every developer-owned surface', function () {
    $this->actingAs(devStaff('developer'));

    $this->get('/admin')->assertOk();
    $this->get('/admin/feature-flags')->assertOk();
    $this->get('/admin/staff')->assertOk();
    $this->get(route('admin.compensation.plan-settings.index'))->assertOk();
    $this->get('/admin/settings')->assertOk();
});

it('DEV-02: admin keeps its business capabilities', function () {
    $this->actingAs(devStaff('admin'));

    $this->get('/admin')->assertOk();
    $this->get('/admin/staff')->assertOk();
    // Monitoring the plan is still allowed; only editing moved.
    $this->get(route('admin.compensation.plan-settings.index'))->assertOk();
    $this->get('/admin/settings')->assertOk();
});

it('DEV-03: admin can still write an admin-owned setting', function () {
    $this->actingAs(devStaff('admin'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.settings.update', 'commerce.shipping.fee_rupees'), ['value' => '75'])
        ->assertRedirect();

    expect(DB::table('settings')->where('key', 'commerce.shipping.fee_rupees')->value('value'))->toBe('75');
});

it('DEV-04: admin cannot write a developer-owned setting', function (string $key) {
    $this->actingAs(devStaff('admin'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.settings.update', $key), ['value' => '1'])
        // 404, not 403: a refusal that names the key would confirm it exists.
        ->assertNotFound();

    expect(DB::table('settings')->where('key', $key)->value('value'))->not->toBe('1');
})->with([
    'comp.tds.rate_bp',
    'payout.min_threshold_paise',
    'commerce.cooling_off.days',
    'placement.spillover.enabled',
    'notifications.email_on_status_change',
]);

it('DEV-05: developer can write a developer-owned setting', function () {
    $this->actingAs(devStaff('developer'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.settings.update', 'payout.min_threshold_paise'), ['value' => '15000'])
        ->assertRedirect();

    expect(DB::table('settings')->where('key', 'payout.min_threshold_paise')->value('value'))->toBe('15000');
});

// ─── Stealth ─────────────────────────────────────────────────────────────────

it('DEV-06: the settings page shows an admin no developer-owned key', function () {
    $res = $this->actingAs(devStaff('admin'))->get('/admin/settings')->assertOk();

    $res->assertSee('commerce.shipping.fee_rupees');
    foreach (['comp.tds.rate_bp', 'payout.min_threshold_paise', 'placement.spillover.enabled'] as $hidden) {
        $res->assertDontSee($hidden);
    }
    // The raw engineer table would dump every row including the hidden ones.
    $res->assertDontSee('Show advanced settings (engineer view)');
});

it('DEV-07: the staff register hides developer accounts and the role name', function () {
    $developer = devStaff('developer', 'hidden-dev@arovolife.test');
    devStaff('admin-finance', 'visible-finance@arovolife.test');

    $res = $this->actingAs(devStaff('admin'))->get('/admin/staff')->assertOk();

    $res->assertSee('visible-finance@arovolife.test');
    $res->assertDontSee($developer->email);
    $res->assertDontSee('developer');
});

it('DEV-08: an admin cannot open or manage a developer account', function () {
    $developer = devStaff('developer');
    $this->actingAs(devStaff('admin'));

    $this->get(route('admin.staff.edit', $developer->id))->assertNotFound();

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.roles', $developer->id), ['roles' => ['admin']])
        ->assertNotFound();

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.status', $developer->id), ['status' => 'frozen'])
        ->assertNotFound();

    expect($developer->fresh()->hasRole('developer'))->toBeTrue();
    expect($developer->fresh()->status)->toBe('active');
});

it('DEV-09: an admin cannot grant the developer role via a crafted POST', function () {
    $target = devStaff('admin-operations');
    $this->actingAs(devStaff('admin'));

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.roles', $target->id), ['roles' => ['developer']])
        ->assertSessionHasErrors('roles.0');

    expect($target->fresh()->hasRole('developer'))->toBeFalse();

    // Same for the create form.
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.staff.store'), [
            'full_name' => 'Sneaky Escalation',
            'email' => 'sneaky@arovolife.test',
            'phone_e164' => '+919812345678',
            'password' => 'LongEnoughPass123',
            'password_confirmation' => 'LongEnoughPass123',
            'roles' => ['developer'],
        ])
        ->assertSessionHasErrors('roles.0');

    expect(User::where('email', 'sneaky@arovolife.test')->exists())->toBeFalse();
});

it('DEV-10: the audit log shows a developer action as a system row', function () {
    $developer = devStaff('developer', 'quiet-dev@arovolife.test');

    // A real developer action that writes an audit row.
    $this->actingAs($developer)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.settings.update', 'comp.tds.rate_bp'), ['value' => '700'])
        ->assertRedirect();

    expect(AuditLog::where('action', 'admin.settings.changed')->value('actor_id'))
        ->toBe($developer->id); // retained in the table for compliance

    $res = $this->actingAs(devStaff('admin'))->get('/admin/audit-log')->assertOk();
    $res->assertDontSee('quiet-dev@arovolife.test');
    $res->assertSee('system');
});

it('DEV-11: an admin cannot impersonate a developer, and the refusal is generic', function () {
    $developer = devStaff('developer');

    $res = $this->actingAs(devStaff('admin'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->from('/admin/distributors')
        ->post(route('admin.impersonate.start', $developer->id));

    $res->assertRedirect('/admin/distributors');
    $res->assertSessionHasErrors(['impersonate' => 'This account cannot be impersonated.']);

    // The wording must not name the role that blocked it.
    $message = session('errors')->getBag('default')->first('impersonate');
    expect($message)->not->toContain('developer');
});

// ─── Login channel ───────────────────────────────────────────────────────────

it('DEV-12: developer signs in with email and lands on the admin console', function () {
    devStaff('developer', 'dev-login@arovolife.test');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/login', ['login' => 'dev-login@arovolife.test', 'password' => 'CorrectHorseBattery1'])
        ->assertRedirect(route('admin.dashboard'));

    expect(auth()->check())->toBeTrue();
});
