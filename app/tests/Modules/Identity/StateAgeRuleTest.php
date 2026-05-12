<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * State-aware age rule (US-1.12 + DSR / Maharashtra Direct Selling rules).
 * Default minimum age is 18; specific states can override via the
 * `compliance.state_age_minimums` setting. The rule is admin-configurable
 * so e.g. a future Tamil Nadu age update doesn't require a code release.
 */
function sarSeedSession(): User
{
    $user = User::create([
        'email' => 'sar-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'pending',
    ]);
    test()->actingAs($user);
    test()->withSession([
        'registration_wizard' => [
            // Personal is step 8 in the canonical 2026-05 order.
            'step' => 8,
            'user_id' => $user->id,
            'sponsor_id' => 1,
            'data' => [],
        ],
    ]);

    return $user;
}

function sarSeedSetting(array $minimums): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => 'compliance.state_age_minimums'],
        ['value' => json_encode($minimums), 'version' => 1, 'updated_at' => now()],
    );
}

it('SAR-01: 19 year old in TG (default rule = 18) is accepted', function () {
    sarSeedSession();
    sarSeedSetting(['MH' => 21]);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/personal', [
        'date_of_birth' => now()->subYears(19)->format('Y-m-d'),
        'state' => 'TG',
        'address' => 'somewhere',
    ]);

    $response->assertRedirect('/register/documents');
    $response->assertSessionHasNoErrors();
});

it('SAR-02: 19 year old in MH (override = 21) is rejected with a clear age error', function () {
    sarSeedSession();
    sarSeedSetting(['MH' => 21]);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/personal', [
        'date_of_birth' => now()->subYears(19)->format('Y-m-d'),
        'state' => 'MH',
        'address' => 'Mumbai somewhere',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('date_of_birth');
});

it('SAR-03: admin lowers MH override to 19 → 19 year old is then accepted', function () {
    sarSeedSession();
    sarSeedSetting(['MH' => 19]);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/personal', [
        'date_of_birth' => now()->subYears(19)->format('Y-m-d'),
        'state' => 'MH',
        'address' => 'Mumbai somewhere',
    ]);

    $response->assertRedirect('/register/documents');
    $response->assertSessionHasNoErrors();
});

it('SAR-04: 17 year old in any state is rejected by the default 18 rule', function () {
    sarSeedSession();
    sarSeedSetting(['MH' => 21]);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/register/personal', [
        'date_of_birth' => now()->subYears(17)->format('Y-m-d'),
        'state' => 'TG',
        'address' => 'somewhere',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('date_of_birth');
});

it('SAR-05: admin can update state age minimums; audit log captures it', function () {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'email' => 'sar-admin-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    sarSeedSetting(['MH' => 21]);

    $this->actingAs($admin);
    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/admin/settings/age-rules', [
        'state_age_minimums' => '{"MH":19,"TN":21}',
    ]);
    $response->assertRedirect();

    $row = DB::table('settings')->where('key', 'compliance.state_age_minimums')->first();
    expect($row->value)->toBe('{"MH":19,"TN":21}');

    $audit = DB::table('audit_log')
        ->where('action', 'admin.settings.state_age_minimums.changed')
        ->first();
    expect($audit)->not->toBeNull();
});

it('SAR-06: admin update rejects invalid JSON / out-of-range ages', function () {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'email' => 'sar-admin-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    sarSeedSetting(['MH' => 21]);

    $this->actingAs($admin);

    // Out of range: 12 is too young
    $response = $this->withoutMiddleware(PreventRequestForgery::class)->post('/admin/settings/age-rules', [
        'state_age_minimums' => '{"MH":12}',
    ]);
    $response->assertSessionHasErrors('state_age_minimums');
    $row = DB::table('settings')->where('key', 'compliance.state_age_minimums')->value('value');
    expect($row)->toBe('{"MH":21}');
});
