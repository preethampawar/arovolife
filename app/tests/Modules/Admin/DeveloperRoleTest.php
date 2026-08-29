<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Livewire\Queues;
use Livewire\Livewire;
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

it('DEV-13: developer reaches the Pulse dashboard', function () {
    $this->actingAs(devStaff('developer'));

    $this->get('/pulse')->assertOk();
});

it('DEV-13c: Pulse cards render when the default cache store refuses to unserialize objects', function () {
    $this->actingAs(devStaff('developer'));

    // Laravel 13's cache.serializable_classes = false makes every object read
    // from a serialising store an incomplete object. Pulse memoises card
    // queries (Collections) through its own cache store, so that store must
    // be one that never serialises — staging 2026-08-29: every card 500'd on
    // Redis. The file store enforces the same rule as Redis, so it stands in.
    config(['cache.default' => 'file', 'cache.serializable_classes' => false]);

    // Pulse cards are lazy: a plain Livewire::test() renders only the
    // placeholder and never runs the query, so lazy loading is switched off.
    Livewire::withoutLazyLoading()->test(Queues::class)->assertOk();
    Livewire::withoutLazyLoading()->test(Queues::class)->assertOk();
});

it('DEV-13b: the Pulse dashboard alone gets a CSP that lets Alpine evaluate', function () {
    $this->actingAs(devStaff('developer'));

    // Pulse's cards are Livewire + Alpine; Alpine compiles its expressions with
    // new Function(), so without 'unsafe-eval' every card stays a skeleton
    // (staging, 2026-08-29). The widening is confined to the dashboard path.
    $pulse = (string) $this->get('/pulse')->headers->get('Content-Security-Policy');
    $site = (string) $this->get('/')->headers->get('Content-Security-Policy');

    expect($pulse)->toContain("'unsafe-eval'")
        ->and($pulse)->toContain("frame-ancestors 'none'")
        ->and($site)->not->toContain("'unsafe-eval'");
});

/**
 * Pulse ships gated on `viewPulse`, which its own default answers with
 * "true if local". A Gate alone cannot hold this surface either way, because
 * AppServiceProvider's Gate::before returns true for every super-staff user
 * before a definition is consulted — so admins would pass. The
 * `role:developer` middleware in config/pulse.php is what actually closes it.
 */
it('DEV-14: an admin cannot open Pulse', function () {
    $this->actingAs(devStaff('admin'));

    $this->get('/pulse')->assertForbidden();
});

it('DEV-15: Pulse is closed to guests', function () {
    $this->get('/pulse')->assertRedirect();
});

// ─── Stealth ─────────────────────────────────────────────────────────────────

it('DEV-06: the settings page shows an admin no developer-owned key', function () {
    $res = $this->actingAs(devStaff('admin'))->get('/admin/settings')->assertOk();

    $res->assertSee('commerce.shipping.fee_rupees');
    // Deduction/threshold keys stay visible read-only (compliance can verify
    // them); everything else the admin doesn't own is absent entirely.
    foreach (['placement.spillover.enabled', 'comp.gsb.pool_rate_bp', 'payments.gateway.stub.enabled'] as $hidden) {
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

it('DEV-10b: staff audit rows about a developer account are hidden, not just actor-masked', function () {
    // Regression (compliance review 2026-07-29): masking actor_email alone
    // leaked the role anyway — a staff.user.created row carries the granted
    // roles in its details payload and resolves its subject to the account's
    // name, disclosing both the account and that the role exists.
    $this->artisan('staff:create', ['email' => 'leaky-dev@arovolife.test', '--role' => 'developer'])
        ->expectsQuestion('Full name', 'Leaky Developer Name')
        ->expectsQuestion('Mobile number (E.164)', '+919876500099')
        ->expectsQuestion('Password (min 12 characters)', 'kQ4-plumBridge82Vex')
        ->expectsQuestion('Confirm password', 'kQ4-plumBridge82Vex')
        ->assertSuccessful();

    expect(AuditLog::where('action', 'staff.user.created')->exists())->toBeTrue();

    $res = $this->actingAs(devStaff('admin'))->get('/admin/audit-log')->assertOk();

    $res->assertDontSee('leaky-dev@arovolife.test');
    $res->assertDontSee('Leaky Developer Name');
    $res->assertDontSee('developer');
    $res->assertDontSee('staff.user.created');
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
