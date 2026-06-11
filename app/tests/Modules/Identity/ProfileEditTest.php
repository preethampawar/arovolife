<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * "My profile" (partner spec 2026-06-11):
 *  - identity is read-only — full name, ADN, and the KYC numbers (PAN /
 *    Aadhaar / bank) are locked and shown MASKED;
 *  - mobile, email and address are editable;
 *  - a mobile/email change requires an emailed OTP (reusable OtpService) and
 *    only takes effect once the code is confirmed.
 */
function profUser(array $overrides = []): User
{
    return User::create(array_merge([
        'full_name' => 'K Ramakrishna',
        'email' => 'prof-'.uniqid().'@example.com',
        'phone_e164' => '+9199'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('prof-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ], $overrides));
}

function profDistributor(User $user): int
{
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => '973708897',
            'pan_hash' => random_bytes(32), 'pan_last4' => '123F',
            'aadhaar_last4' => '9012',
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0001234',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return $id;
}

function profPatch(User $user, array $data)
{
    return test()->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->patch(route('profile.update'), $data);
}

/** Capture the OTP code from the (faked) email so the test can confirm it. */
function profCapturedOtp(): string
{
    $code = null;
    Notification::assertSentOnDemand(OtpCodeNotification::class, function ($notification) use (&$code) {
        $code = $notification->code;

        return true;
    });

    return (string) $code;
}

beforeEach(function (): void {
    Cache::flush();
});

it('PROF-01: shows masked identity (read-only) and editable contact fields', function (): void {
    $user = profUser();
    profDistributor($user);

    $response = $this->actingAs($user)->get(route('profile.show'))->assertOk();

    $response->assertSee('K Ramakrishna');            // full name (read-only)
    $response->assertSee('973708897');                // ADN
    $response->assertSee('XXXXXX123F');               // PAN masked
    $response->assertSee('XXXX XXXX 9012');           // Aadhaar masked
    $response->assertSee('SBIN0001234');              // bank IFSC
    $response->assertSee('value="973708897" disabled', false);
    $response->assertSee('name="phone_e164"', false);
    $response->assertSee('name="email"', false);
    $response->assertSee('name="address"', false);
});

it('PROF-02: an address-only change saves immediately with no OTP', function (): void {
    Notification::fake();
    $user = profUser(['address' => null]);

    profPatch($user, [
        'phone_e164' => $user->phone_e164,
        'email' => $user->email,
        'address' => '12 MG Road, Hyderabad, TG 500032',
    ])->assertRedirect(route('profile.show'))->assertSessionHas('status');

    expect($user->refresh()->address)->toBe('12 MG Road, Hyderabad, TG 500032');
    Notification::assertNothingSent();
});

it('PROF-03: full name is NOT editable here — a posted full_name is ignored', function (): void {
    Notification::fake();
    $user = profUser(['full_name' => 'K Ramakrishna']);

    profPatch($user, [
        'full_name' => 'Hacker Name',                 // ignored
        'phone_e164' => $user->phone_e164,
        'email' => $user->email,
        'address' => 'New address line',
    ])->assertRedirect();

    expect($user->refresh()->full_name)->toBe('K Ramakrishna');
});

it('PROF-04: changing the email does NOT save immediately — it sends an OTP and opens the modal', function (): void {
    Notification::fake();
    $user = profUser();
    $newEmail = 'changed-'.uniqid().'@example.com';

    profPatch($user, [
        'phone_e164' => $user->phone_e164,
        'email' => $newEmail,
    ])->assertRedirect(route('profile.show'))->assertSessionHas('profile_otp');

    // Not applied yet.
    expect($user->refresh()->email)->not->toBe($newEmail);
    // OTP emailed to the NEW address.
    Notification::assertSentOnDemand(OtpCodeNotification::class);
});

it('PROF-05: confirming the OTP applies the email change and marks it unverified', function (): void {
    Notification::fake();
    $user = profUser();
    $newEmail = 'confirmed-'.uniqid().'@example.com';

    profPatch($user, ['phone_e164' => $user->phone_e164, 'email' => $newEmail]);
    $code = profCapturedOtp();

    test()->actingAs($user)->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.otp.confirm'), ['otp' => $code])
        ->assertRedirect(route('profile.show'))
        ->assertSessionHas('status');

    $user->refresh();
    expect($user->email)->toBe($newEmail);
    expect($user->email_verified_at)->toBeNull();
    expect(AuditLog::where('action', 'profile.updated')->where('actor_id', $user->id)->count())->toBeGreaterThan(0);
});

it('PROF-06: a wrong OTP does not apply the change', function (): void {
    Notification::fake();
    $user = profUser();
    $newEmail = 'wrong-'.uniqid().'@example.com';

    profPatch($user, ['phone_e164' => $user->phone_e164, 'email' => $newEmail]);

    test()->actingAs($user)->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.otp.confirm'), ['otp' => '000000'])
        ->assertSessionHasErrors('otp');

    expect($user->refresh()->email)->not->toBe($newEmail);
});

it('PROF-07: confirming a mobile change applies the new number', function (): void {
    Notification::fake();
    $user = profUser();
    $newPhone = '+919812345678';

    profPatch($user, ['phone_e164' => $newPhone, 'email' => $user->email])
        ->assertSessionHas('profile_otp');
    expect($user->refresh()->phone_e164)->not->toBe($newPhone);   // held

    $code = profCapturedOtp();
    test()->actingAs($user)->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.otp.confirm'), ['otp' => $code])
        ->assertRedirect(route('profile.show'));

    expect($user->refresh()->phone_e164)->toBe($newPhone);
});

it('PROF-10: the OTP modal renders with a resend button + 30s cooldown', function (): void {
    $user = profUser();

    $this->actingAs($user)
        ->withSession(['profile_otp' => ['email_masked' => 'r•••@example.com']])
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee('id="otpModal"', false)
        ->assertSee('id="otpResendBtn"', false)
        ->assertSee('Resend code in', false);   // countdown label in the script
});

it('PROF-09: a value taken AFTER the OTP was issued is rejected at confirm (no 500)', function (): void {
    Notification::fake();
    $user = profUser();
    $newEmail = 'race-'.uniqid().'@example.com';

    // Submit a change → OTP issued for $newEmail.
    profPatch($user, ['phone_e164' => $user->phone_e164, 'email' => $newEmail]);
    $code = profCapturedOtp();

    // Someone else grabs that email before the user confirms.
    profUser(['email' => $newEmail]);

    test()->actingAs($user)->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.otp.confirm'), ['otp' => $code])
        ->assertRedirect(route('profile.show'))
        ->assertSessionHasErrors('otp');

    expect($user->refresh()->email)->not->toBe($newEmail);
});

it('PROF-08: email must be unique — checked before any OTP is sent', function (): void {
    Notification::fake();
    profUser(['email' => 'taken@example.com']);
    $user = profUser();

    profPatch($user, ['phone_e164' => $user->phone_e164, 'email' => 'taken@example.com'])
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
    expect($user->refresh()->email)->not->toBe('taken@example.com');
});
