<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regression: customers reported on staging that after an admin reset a
 * distributor's password, the distributor could not log in with the new
 * password by either email OR ADN. This test pins the full flow:
 *
 *   1. Admin sets a new password via /admin/distributors/{id}/set-password.
 *   2. Distributor logs in with the new password using their email.
 *   3. Distributor logs in with the new password using their 9-digit ADN.
 *
 * Both #2 and #3 must end up authenticated and redirected away from
 * /login. If either fails, the bug is back.
 */

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('distributor');
});

function aprlMakeDistributor(string $emailPrefix, string $oldPassword, string $adn): array
{
    $user = User::create([
        'email' => $emailPrefix.rand(1000, 9999).'@arovolife.test',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => Hash::make($oldPassword),
        'password_set_at' => now(),
        'status' => 'active',
        'full_name' => 'Test Distributor '.$emailPrefix,
    ]);
    $user->assignRole('distributor');

    // Minimal distributor row — enough columns satisfied to land in the
    // table; tests don't touch tree placement.
    DB::table('distributors')->insert([
        'user_id' => $user->id,
        'adn' => $adn,
        'pan_hash' => random_bytes(32),
        'pan_last4' => '0000',
        'bank_account_enc' => 'stub',
        'bank_ifsc' => 'SBIN0000000',
        'sponsor_id' => 1,
        'placement_parent_id' => 1,
        'placement_side' => 'L',
        'side_chosen_by' => 'referral_explicit',
        'depth' => 1,
        'effective_date' => now()->format('Y-m-d H:i:s.v'),
        'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
        'state' => 'TS',
        'is_primary_couple' => 0,
        'created_at' => now()->format('Y-m-d H:i:s.v'),
        'updated_at' => now()->format('Y-m-d H:i:s.v'),
    ]);

    return [$user, $adn];
}

function aprlMakeAdmin(): User
{
    $admin = User::create([
        'email' => 'admin-'.rand(1000, 9999).'@arovolife.test',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => Hash::make('AdminCorrectPass!2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'full_name' => 'Test Admin',
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('APR-01: admin sets a new password → distributor can sign in with EMAIL', function () {
    $newPassword = 'BrandNew-9pass!ZZ';
    [$user]      = aprlMakeDistributor('email-login-', 'OldCorrectPass!2026', '900100200');
    $admin       = aprlMakeAdmin();
    $distributor = Distributor::query()->where('user_id', $user->id)->firstOrFail();

    // Clear any pre-existing rate-limit state for both keys we'll touch.
    RateLimiter::clear('login:'.strtolower($user->email).'|127.0.0.1');

    // 1. Admin resets the password through the real endpoint.
    $resetResponse = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/distributors/'.$distributor->id.'/set-password', [
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ]);
    $resetResponse->assertRedirect();
    expect(session('status'))->toContain('New password set');

    // Sanity: stored hash matches the new password.
    $user->refresh();
    expect(Hash::check($newPassword, $user->password_hash))->toBeTrue();
    expect($user->password_set_at)->not->toBeNull();

    // 2. Switch contexts — sign out admin, then attempt distributor login
    //    via email + new password.
    auth()->logout();
    RateLimiter::clear('login:'.strtolower($user->email).'|127.0.0.1');

    $loginResponse = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/login', ['login' => $user->email, 'password' => $newPassword]);

    $loginResponse->assertRedirect('/dashboard');
    expect(auth()->check())->toBeTrue();
    expect(auth()->id())->toBe($user->id);
});

it('APR-02: admin sets a new password → distributor can sign in with ADN', function () {
    $newPassword = 'AdnRoute-9pass!QQ';
    $adn         = '900200300';
    [$user]      = aprlMakeDistributor('adn-login-', 'OldCorrectPass!2026', $adn);
    $admin       = aprlMakeAdmin();
    $distributor = Distributor::query()->where('user_id', $user->id)->firstOrFail();

    RateLimiter::clear('login:'.strtolower($user->email).'|127.0.0.1');

    // 1. Admin resets the password.
    $resetResponse = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/distributors/'.$distributor->id.'/set-password', [
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ]);
    $resetResponse->assertRedirect();

    // 2. Logout admin and try logging in as the distributor using ADN.
    auth()->logout();
    RateLimiter::clear('login:'.strtolower($user->email).'|127.0.0.1');

    $loginResponse = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/login', ['login' => $adn, 'password' => $newPassword]);

    $loginResponse->assertRedirect('/dashboard');
    expect(auth()->check())->toBeTrue();
    expect(auth()->id())->toBe($user->id);
});

it('APR-04: REGRESSION — pre-reset failed attempts must not lock the user out after admin reset', function () {
    $oldPassword = 'OldCorrectPass!2026';
    $newPassword = 'BrandNew-9pass!ZZ';
    [$user]      = aprlMakeDistributor('throttle-', $oldPassword, '900400500');
    $admin       = aprlMakeAdmin();
    $distributor = Distributor::query()->where('user_id', $user->id)->firstOrFail();

    $loginKey = 'login:'.strtolower($user->email).'|127.0.0.1';
    RateLimiter::clear($loginKey);

    // Step 1: user fires 5 bad-password attempts to fill the rate-limit
    // bucket, mirroring what a forgetful user does before asking for a
    // reset on the helpdesk channel.
    for ($i = 0; $i < 5; $i++) {
        $this->withoutMiddleware(PreventRequestForgery::class)
            ->post('/login', ['login' => $user->email, 'password' => 'wrong-'.$i])
            ->assertRedirect();
    }
    expect(RateLimiter::tooManyAttempts($loginKey, 5))->toBeTrue();

    // Step 2: admin resets the password via the real endpoint.
    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/distributors/'.$distributor->id.'/set-password', [
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ])
        ->assertRedirect();
    auth()->logout();

    // Step 3: user attempts login with the CORRECT new password.
    // EXPECTED (after fix): authenticated → redirected to /dashboard.
    // CURRENT BUG: still throttled, error mentions "Too many failed".
    $resp = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/login', ['login' => $user->email, 'password' => $newPassword]);

    $resp->assertRedirect('/dashboard');
    expect(auth()->check())->toBeTrue();
    expect(auth()->id())->toBe($user->id);
});

it('APR-03: stale password no longer works after reset', function () {
    $oldPassword = 'OldCorrectPass!2026';
    $newPassword = 'NewCorrectPass!2026';
    [$user]      = aprlMakeDistributor('stale-', $oldPassword, '900300400');
    $admin       = aprlMakeAdmin();
    $distributor = Distributor::query()->where('user_id', $user->id)->firstOrFail();

    RateLimiter::clear('login:'.strtolower($user->email).'|127.0.0.1');

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/admin/distributors/'.$distributor->id.'/set-password', [
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ])
        ->assertRedirect();

    auth()->logout();
    RateLimiter::clear('login:'.strtolower($user->email).'|127.0.0.1');

    // Old password must NO LONGER work.
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/login', ['login' => $user->email, 'password' => $oldPassword])
        ->assertSessionHasErrors('login');
    expect(auth()->check())->toBeFalse();
});
