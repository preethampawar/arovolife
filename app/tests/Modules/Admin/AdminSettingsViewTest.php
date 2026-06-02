<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Admin platform-settings UI tests. The page was redesigned in 2026-05 to
 * group raw key/value rows into operator-friendly sections with type-aware
 * inputs (toggles, number fields, JSON textareas) and a per-setting save
 * endpoint. These tests pin that contract.
 */
function asvSeedAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'Settings Admin',
        'email' => 'asv-admin-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

function asvSeedSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

it('AS-01: GET /admin/settings renders friendly section headers, not just a raw table', function (): void {
    $admin = asvSeedAdmin();
    $this->actingAs($admin);

    // Seed a representative key so the relevant section actually renders.
    asvSeedSetting('commerce.checkout.enabled', 'true');
    asvSeedSetting('commerce.cooling_off.days', '30');
    asvSeedSetting('commerce.self_purchase.earns_bv', 'true');

    $response = $this->get('/admin/settings');

    $response->assertStatus(200);
    // Friendly section labels (from controller's groups() registry).
    $response->assertSee('Commerce');
    $response->assertSee('Cooling-off');
    $response->assertSee('Self-purchase');
    // Friendly per-setting labels — not raw keys as headings.
    $response->assertSee('Storefront checkout');
    $response->assertSee('Cooling-off period (days)');
    $response->assertSee('Self-purchase earns BV');
    // The engineer view is still present but collapsed.
    $response->assertSee('Show advanced settings (engineer view)');
});

it('AS-02: toggling a boolean setting via POST flips the stored value', function (): void {
    $admin = asvSeedAdmin();
    $this->actingAs($admin);

    asvSeedSetting('commerce.guest_checkout.enabled', 'true');

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/commerce.guest_checkout.enabled', ['value' => 'false']);

    $response->assertRedirect(route('admin.settings'));

    $stored = DB::table('settings')->where('key', 'commerce.guest_checkout.enabled')->value('value');
    expect($stored)->toBe('false');

    // Audit log row was written with the before/after diff.
    $audit = AuditLog::where('action', 'admin.settings.changed')
        ->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->details['key'])->toBe('commerce.guest_checkout.enabled');
    expect($audit->details['before'])->toBe('true');
    expect($audit->details['after'])->toBe('false');

    // Flip back ON — also accepts the "true" string from the toggle's hidden input.
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/commerce.guest_checkout.enabled', ['value' => 'true']);
    $stored = DB::table('settings')->where('key', 'commerce.guest_checkout.enabled')->value('value');
    expect($stored)->toBe('true');
});

it('AS-03: typing a number into the cooling-off-days field and saving persists', function (): void {
    $admin = asvSeedAdmin();
    $this->actingAs($admin);

    asvSeedSetting('commerce.cooling_off.days', '30');

    // Statutory floor is 30; raising to 45 must succeed.
    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/commerce.cooling_off.days', ['value' => '45']);

    $response->assertRedirect(route('admin.settings'));
    expect(DB::table('settings')->where('key', 'commerce.cooling_off.days')->value('value'))->toBe('45');

    // Version bumped from 1 -> 2.
    expect(DB::table('settings')->where('key', 'commerce.cooling_off.days')->value('version'))->toBe(2);
});

it('AS-04: lowering cooling-off below the statutory 30-day floor is rejected', function (): void {
    $admin = asvSeedAdmin();
    $this->actingAs($admin);

    asvSeedSetting('commerce.cooling_off.days', '30');

    // 7 days would violate DSR 2021. Controller clamps via registry min=30.
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/commerce.cooling_off.days', ['value' => '7']);

    // Value did NOT change.
    expect(DB::table('settings')->where('key', 'commerce.cooling_off.days')->value('value'))->toBe('30');
});

it('AS-05: compensation switches are read-only from this UI (POST returns 403)', function (): void {
    $admin = asvSeedAdmin();
    $this->actingAs($admin);

    asvSeedSetting('compensation.payout.enabled', 'false');

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/compensation.payout.enabled', ['value' => 'true']);

    $response->assertStatus(403);
    expect(DB::table('settings')->where('key', 'compensation.payout.enabled')->value('value'))->toBe('false');
});

it('AS-06: unknown setting key returns 404', function (): void {
    $admin = asvSeedAdmin();
    $this->actingAs($admin);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/does.not.exist', ['value' => 'true']);

    $response->assertStatus(404);
});

it('AS-07: non-admin cannot reach the settings page', function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::create([
        'full_name' => 'Plain user',
        'email' => 'asv-plain-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('xpass'),
        'status' => 'active',
    ]);
    $this->actingAs($user);

    $response = $this->get('/admin/settings');
    expect($response->status())->not->toBe(200);
});

it('AS-08: settings index page renders even when the table is empty (defaults are used)', function (): void {
    $admin = asvSeedAdmin();
    $this->actingAs($admin);

    // Wipe everything — controller must still render using the registry defaults.
    DB::table('settings')->delete();

    $response = $this->get('/admin/settings');
    $response->assertStatus(200);
    $response->assertSee('Storefront checkout');
});
