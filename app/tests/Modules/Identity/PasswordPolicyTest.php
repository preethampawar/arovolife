<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Password policy (T-2.3, relaxed from the master plan's 12-char target
 * down to 8 chars per project decision — entropy/HIBP/lockout still apply):
 *  - 8 character minimum
 *  - zxcvbn entropy score ≥ 3
 *  - rejected if known to HaveIBeenPwned
 *  - login is rate-limited; after 5 failed attempts an IP+email pair is
 *    locked out for ~15 minutes
 */
function ppRegister(array $overrides = []): TestResponse
{
    // Seed a sponsor so the registration form's referral-link entry succeeds.
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        $sponsorUser = User::create([
            'email' => 'spons-'.rand(1000, 9999).'@test.com',
            'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
            'password_hash' => bcrypt('x'),
            'status' => 'active',
        ]);

        $sponsorId = DB::table('distributors')->insertGetId([
            'user_id' => $sponsorUser->id,
            'adn' => 'AROSPONS'.str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $sponsorId)->update([
            'sponsor_id' => $sponsorId, 'placement_parent_id' => $sponsorId,
        ]);
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    $sponsorAdn = DB::table('distributors')->where('id', $sponsorId)->value('adn');

    // Per ADR-0003 the wizard requires the referral-link intent (sponsor +
    // placement, optional side) to be stashed in session BEFORE the user
    // can POST step 1. Seed it directly to mirror what RegistrationWizardController::start()
    // would produce when the visitor arrives via a valid referral link.
    test()->withSession([
        'registration_intent' => [
            'sponsor_id' => $sponsorId,
            'placement_id' => $sponsorId,
            'side_opt' => null,
            'sponsor_adn' => $sponsorAdn,
            'placement_adn' => $sponsorAdn,
        ],
        // Orientation now runs as step 1 (public, before account creation)
        // and writes this flag on success. Seed it directly so this test
        // can target the password policy without walking through the quiz.
        'orientation_passed_at' => now()->toIso8601String(),
    ]);

    $defaults = [
        'full_name' => 'Strong User',
        'email' => 'pp-'.rand(1000, 9999).'@test.com',
        'phone_e164' => str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password' => 'qz!9!Tx7%KrPmNvX',
        'password_confirmation' => 'qz!9!Tx7%KrPmNvX',
    ];

    return test()->withoutMiddleware(PreventRequestForgery::class)
        ->post('/register/account', array_merge($defaults, $overrides));
}

it('PP-01: passwords shorter than 8 chars are rejected by the min-length rule specifically', function () {
    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response("ABCDE:0\n", 200),
    ]);
    $weak = ppRegister(['password' => 'Sh1!t', 'password_confirmation' => 'Sh1!t']);
    expect(strlen('Sh1!t'))->toBe(5); // sanity
    $weak->assertSessionHasErrors('password');

    // Pin the min-length rule as the rejection cause. A 5-char password also
    // fails StrongPassword (zxcvbn) and might match HIBP, but we want this
    // test to fail loudly if min:8 is ever quietly removed.
    $errors = session('errors')->getBag('default')->get('password');
    expect(implode(' ', $errors))->toContain('at least 8');
});

it('PP-02: low-entropy passwords are rejected by zxcvbn', function () {
    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response("ABCDE:0\n", 200),
    ]);
    // 12 chars but trivially predictable (a top-1000 password padded out)
    $response = ppRegister([
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ]);
    $response->assertSessionHasErrors('password');
});

it('PP-03: a password that HIBP says is breached is rejected', function () {
    // Simulate HIBP returning a hit. The processor sends the first 5 hex
    // chars of SHA1(password) and gets back lines `SUFFIX:count`. We tell
    // Http::fake to ALWAYS return a body containing the SHA1 suffix of
    // the password we're testing.
    $password = 'qz!9!Tx7%KrPmNvX';
    $sha1 = strtoupper(sha1($password));
    $suffix = substr($sha1, 5);

    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response("{$suffix}:42\n", 200),
    ]);

    $response = ppRegister([
        'password' => $password,
        'password_confirmation' => $password,
    ]);
    $response->assertSessionHasErrors('password');
});

it('PP-04: a strong, non-breached password registers successfully', function () {
    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response("ABCDE:0\n", 200),
    ]);
    $response = ppRegister();
    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
});

it('PP-05: 5 failed login attempts throttle the 6th', function () {
    RateLimiter::clear('login:foo@test.com|127.0.0.1');

    $user = User::create([
        'email' => 'foo@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => Hash::make('correct-horse-battery-staple'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);

    // 5 wrong attempts
    for ($i = 0; $i < 5; $i++) {
        $this->withoutMiddleware(PreventRequestForgery::class)
            ->post('/login', ['email' => 'foo@test.com', 'password' => 'wrongpw'])
            ->assertRedirect();
    }

    // 6th attempt — even with the right password — must be throttled
    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/login', ['email' => 'foo@test.com', 'password' => 'correct-horse-battery-staple']);
    $response->assertSessionHasErrors('email');
    $errors = session('errors')->getBag('default')->all();
    expect(implode(' ', $errors))->toContain('many');
});
