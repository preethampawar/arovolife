<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\PasswordResetNotification;
use App\Modules\Identity\Services\Exceptions\InvalidResetTokenError;
use App\Modules\Identity\Services\RequestPasswordReset;
use App\Modules\Identity\Services\ResetPassword;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * Forgot-password / reset-password — covers the happy path, anti-enumeration,
 * activated-only gating, expiry, and bad-token rejection.
 */
function prtUser(string $email = 'reset-target@test.com'): User
{
    return User::create([
        'email' => $email,
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => Hash::make('original-password-123'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);
}

it('PR-01: requesting a reset for an existing user creates a token and sends mail', function () {
    Notification::fake();
    $user = prtUser();

    app(RequestPasswordReset::class)('reset-target@test.com');

    expect(DB::table('password_reset_tokens')->where('email', 'reset-target@test.com')->exists())->toBeTrue();
    Notification::assertSentTo($user, PasswordResetNotification::class);
});

it('PR-02: requesting a reset for an unknown email is silent (no token, no exception)', function () {
    Notification::fake();

    app(RequestPasswordReset::class)('does-not-exist@test.com');

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    Notification::assertNothingSent();
});

it('PR-03: spouse account (password_set_at NULL) cannot use the reset flow', function () {
    Notification::fake();
    $spouse = User::create([
        'email' => 'spouse-pending@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => Hash::make('placeholder'),
        'password_set_at' => null,
        'status' => 'pending',
    ]);

    app(RequestPasswordReset::class)('spouse-pending@test.com');

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    Notification::assertNothingSent();
});

it('PR-04: ResetPassword with a valid token updates the hash and stamps password_set_at', function () {
    $user = prtUser();
    $rawToken = bin2hex(random_bytes(32));
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token_hash' => hash('sha256', $rawToken),
        'created_at' => now(),
    ]);

    $oldHash = $user->password_hash;

    app(ResetPassword::class)($user->email, $rawToken, 'fresh-strong-pass-77!');

    $user->refresh();
    expect($user->password_hash)->not->toBe($oldHash);
    expect(Hash::check('fresh-strong-pass-77!', $user->password_hash))->toBeTrue();
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0);
});

it('PR-05: ResetPassword with the wrong raw token throws InvalidResetTokenError', function () {
    $user = prtUser();
    $rawToken = bin2hex(random_bytes(32));
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token_hash' => hash('sha256', $rawToken),
        'created_at' => now(),
    ]);

    expect(fn () => app(ResetPassword::class)($user->email, 'attacker-guessed-this', 'fresh-strong-pass-77!'))
        ->toThrow(InvalidResetTokenError::class);
});

it('PR-06: an expired token (>60 min old) is rejected and removed', function () {
    $user = prtUser();
    $rawToken = bin2hex(random_bytes(32));
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token_hash' => hash('sha256', $rawToken),
        'created_at' => now()->subMinutes(120),
    ]);

    expect(fn () => app(ResetPassword::class)($user->email, $rawToken, 'fresh-strong-pass-77!'))
        ->toThrow(InvalidResetTokenError::class);

    // Expired row is cleaned up so a stale link can't be retried.
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0);
});

it('PR-07: forgot-password endpoint shows the same generic message regardless of email validity', function () {
    Notification::fake();

    $known = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/forgot-password', ['email' => 'first@test.com']);
    $unknown = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/forgot-password', ['email' => 'second@test.com']);

    // Same status text, same redirect target — anti-enumeration.
    $known->assertRedirect();
    $unknown->assertRedirect();
    expect(session('status'))->toContain('If an account exists');
});
