<?php

declare(strict_types=1);

/**
 * LOG-1: every failed credential attempt leaves a row in the tamper-evident
 * audit log — the submitted identifier and the IP, never the password.
 */

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin');
});

/**
 * A staff account: staff sign in by email, so this reaches the credential
 * check without tripping the identifier-channel guard (which returns before
 * any failed-credential accounting).
 */
function laUser(string $password = 'correct-horse'): User
{
    $user = User::create([
        'full_name' => 'Audit Login User',
        'email' => 'la-'.uniqid().'@arovolife.test',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => Hash::make($password),
        'password_set_at' => now(),
        'status' => 'active',
    ]);
    $user->assignRole('admin');

    return $user;
}

it('LOG-1-01: a wrong password for a known user writes an audit row without the password', function (): void {
    $user = laUser();

    $this->post(route('login.post'), ['login' => $user->email, 'password' => 'wrong-password'])
        ->assertRedirect();

    $row = AuditLog::where('action', 'auth.login_failed')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->subject_id)->toBe($user->id)
        ->and($row->actor_id)->toBeNull()
        ->and($row->details['login'])->toBe($user->email)
        ->and(json_encode($row->details))->not->toContain('wrong-password');
});

it('LOG-1-02: an unknown identifier still writes an audit row with a null subject', function (): void {
    $this->post(route('login.post'), ['login' => 'nobody@arovolife.test', 'password' => 'whatever'])
        ->assertRedirect();

    $row = AuditLog::where('action', 'auth.login_failed')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->subject_id)->toBeNull()
        ->and($row->details['login'])->toBe('nobody@arovolife.test');
});

it('LOG-1-03: a password or Aadhaar mistyped into the login box is never stored verbatim', function (): void {
    // The two realistic mis-pastes: a password, and a 12-digit Aadhaar (the
    // ADN is 9 digits, so the shapes are distinguishable).
    foreach (['hunter2-my-real-password', '123456789012'] as $mistyped) {
        $this->post(route('login.post'), ['login' => $mistyped, 'password' => 'x'])
            ->assertRedirect();

        $row = AuditLog::where('action', 'auth.login_failed')->latest('id')->first();

        expect($row->details['login'])->toBe('malformed')
            ->and(json_encode($row->details))->not->toContain($mistyped)
            ->and($row->details['login_sha256'])->toBe(hash('sha256', $mistyped));
    }
});

it('LOG-1-04: a successful login writes no failed-login row', function (): void {
    $user = laUser('correct-horse');

    $this->post(route('login.post'), ['login' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect();

    expect(AuditLog::where('action', 'auth.login_failed')->count())->toBe(0);
});
