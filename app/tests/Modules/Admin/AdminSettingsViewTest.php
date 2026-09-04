<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Admin platform-settings UI tests. The page was redesigned in 2026-05 to
 * group raw key/value rows into operator-friendly sections with type-aware
 * inputs (toggles, number fields, JSON textareas) and a per-setting save
 * endpoint. These tests pin that contract.
 */
/**
 * Settings keys carry a per-key owner. `admin` owns the business levers
 * (registration, commerce); everything that changes what the platform pays,
 * a statutory term, or how the system behaves is owned by the
 * platform-configuration role and is invisible to anyone else — hence most of
 * these tests act as `developer` to exercise the full page.
 */
function asvSeedAdmin(string $role = 'developer'): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $admin = User::create([
        'full_name' => 'Settings Admin',
        'email' => 'asv-admin-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole($role);

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
    $response->assertSee('Raw settings table');
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

it('AS-05c: a business admin sees only the settings it owns', function (): void {
    $this->actingAs(asvSeedAdmin('admin'));
    asvSeedSetting('commerce.checkout.enabled', 'true');

    $response = $this->get('/admin/settings')->assertStatus(200);

    // Owned: commerce + registration — editable.
    $response->assertSee('Storefront checkout');

    // Statutory / deduction values stay VISIBLE for compliance verification
    // even though they are not editable here.
    $response->assertSee('Cooling-off period (days)');
    $response->assertSee('Shown for reference');

    // Everything else the admin does not own is absent entirely.
    $response->assertDontSee('Admin charge cap — weekly batch (paise)');
    $response->assertDontSee('Raw settings table');
});

it('AS-05d: an admin cannot write the statutory values it can see', function (): void {
    $this->actingAs(asvSeedAdmin('admin'));
    asvSeedSetting('commerce.cooling_off.days', '30');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/commerce.cooling_off.days', ['value' => '45'])
        ->assertNotFound();

    expect(DB::table('settings')->where('key', 'commerce.cooling_off.days')->value('value'))->toBe('30');
});

it('AS-05b: the Round-5 payout caps render and are admin-editable end-to-end', function (): void {
    $admin = asvSeedAdmin();
    $this->actingAs($admin);

    // These render from registry defaults even with no DB row present.
    $response = $this->get('/admin/settings');
    $response->assertStatus(200);
    $response->assertSee('Admin charge cap — weekly batch');
    $response->assertSee('Admin charge cap — monthly batch');
    $response->assertSee('Monthly income cap');

    // Editing one persists AND is read back by the engine's settings service —
    // proving the registry ⇄ CompensationPlanSettingsService wiring is closed
    // (the gap this change fixes). A fresh instance avoids the lazy scalar cache.
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/comp.admin_charge.weekly_cap_paise', ['value' => '2000000'])
        ->assertRedirect(route('admin.settings'));

    expect(DB::table('settings')->where('key', 'comp.admin_charge.weekly_cap_paise')->value('value'))->toBe('2000000');

    $plan = new CompensationPlanSettingsService;
    expect($plan->adminChargeWeeklyCapPaise())->toBe(2_000_000);
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

/**
 * The 'date' string format was added so the personal-BV top-up go-live
 * boundary is tunable from the UI like every other plan scalar. It must reject
 * anything that would not round-trip to the exact Y-m-d string the GSB engine
 * compares accruals against — Carbon would happily coerce "next tuesday" or
 * roll "2026-02-31" over into March.
 */
it('AS-DATE-01: accepts a real calendar date for a date-format plan setting', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    $admin = asvSeedAdmin();
    asvSeedSetting('comp.gsb.topup_golive_date', '1970-01-01');

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/comp.gsb.topup_golive_date', ['value' => '2026-07-21'])
        ->assertRedirect(route('admin.settings'));

    expect(DB::table('settings')->where('key', 'comp.gsb.topup_golive_date')->value('value'))
        ->toBe('2026-07-21');
});

it('AS-DATE-02: rejects non-dates and dates that do not exist', function (string $bad): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    $admin = asvSeedAdmin();
    asvSeedSetting('comp.gsb.topup_golive_date', '1970-01-01');

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/comp.gsb.topup_golive_date', ['value' => $bad]);

    // Unchanged — a rejected value must never reach the settings table.
    expect(DB::table('settings')->where('key', 'comp.gsb.topup_golive_date')->value('value'))
        ->toBe('1970-01-01');
})->with([
    'prose date' => 'next tuesday',
    'non-existent day' => '2026-02-31',
    'wrong separator' => '21/07/2026',
    'partial date' => '2026-07',
    'garbage' => 'not-a-date',
]);

it('AS-FLAG-01: fortune and ADC settings vanish from the page while their feature flags are off', function (): void {
    asvSeedSetting('comp.fortune.pool_rate_bp', '500');
    asvSeedSetting('comp.adc.rate_bp', '300');

    // Flags default to off — no trace of the keys, in the friendly sections
    // or in the developer-only raw table.
    $this->actingAs(asvSeedAdmin())
        ->get('/admin/settings')
        ->assertOk()
        ->assertDontSee('comp.fortune.pool_rate_bp')
        ->assertDontSee('comp.fortune.exclude_rank_6')
        ->assertDontSee('comp.adc.rate_bp')
        ->assertDontSee('comp.adc.cap_paise')
        ->assertDontSee('comp.admin_charge.applies_to_fortune')
        ->assertDontSee('comp.admin_charge.applies_to_adc');

    // Flags on — the keys are back.
    Feature::for(null)->activate(FortuneBonusFeature::class);
    Feature::for(null)->activate(AreteDevelopmentCenterBonusFeature::class);

    $this->actingAs(asvSeedAdmin())
        ->get('/admin/settings')
        ->assertOk()
        ->assertSee('comp.fortune.pool_rate_bp')
        ->assertSee('comp.adc.rate_bp');
});

it('AS-FLAG-02: writing a fortune or ADC setting 404s while its feature flag is off', function (): void {
    asvSeedSetting('comp.fortune.pool_rate_bp', '500');

    $this->actingAs(asvSeedAdmin())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/comp.fortune.pool_rate_bp', ['value' => '600'])
        ->assertNotFound();
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/comp.adc.rate_bp', ['value' => '400'])
        ->assertNotFound();

    expect(DB::table('settings')->where('key', 'comp.fortune.pool_rate_bp')->value('value'))->toBe('500');

    // Flag on — the same write goes through.
    Feature::for(null)->activate(FortuneBonusFeature::class);
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/comp.fortune.pool_rate_bp', ['value' => '600'])
        ->assertRedirect(route('admin.settings'));
    expect(DB::table('settings')->where('key', 'comp.fortune.pool_rate_bp')->value('value'))->toBe('600');
});

it('AS-FLAG-03: every stream config carries its feature flag — GSB, MSB, GBB, Rank, Awards, Repurchase keys vanish while off', function (): void {
    asvSeedSetting('comp.gsb.pool_rate_bp', '4500');
    asvSeedSetting('comp.repurchase.rate_bp', '1000');

    // All bonus flags default to off — no trace of any stream's keys.
    $this->actingAs(asvSeedAdmin())
        ->get('/admin/settings')
        ->assertOk()
        ->assertDontSee('comp.gsb.pool_rate_bp')
        ->assertDontSee('comp.gsb.min_bv_paise')
        ->assertDontSee('comp.msb.pool_rate_bp')
        ->assertDontSee('comp.gbb.pool_rate_bp')
        ->assertDontSee('comp.rank.envelope_bp')
        ->assertDontSee('comp.repurchase.rate_bp')
        ->assertDontSee('comp.admin_charge.applies_to_gsb')
        ->assertDontSee('comp.admin_charge.applies_to_mb')
        ->assertDontSee('comp.admin_charge.applies_to_awards');

    // Writes 404 while off.
    $this->actingAs(asvSeedAdmin())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/settings/comp.gsb.pool_rate_bp', ['value' => '4000'])
        ->assertNotFound();
    expect(DB::table('settings')->where('key', 'comp.gsb.pool_rate_bp')->value('value'))->toBe('4500');

    // Flags on — the keys are back.
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $this->actingAs(asvSeedAdmin())
        ->get('/admin/settings')
        ->assertOk()
        ->assertSee('comp.gsb.pool_rate_bp')
        ->assertSee('comp.repurchase.rate_bp');
});
