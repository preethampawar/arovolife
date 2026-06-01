<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * AED-01 .. AED-05 — admin-driven distributor profile edit + password
 * reset action. The locked fields (PAN / Aadhaar / ADN / sponsor /
 * placement) must be silently dropped by the validator regardless of
 * what the form submits, and every accepted change must produce an
 * audit_log entry with a from/to diff (or a from_redacted/to_redacted
 * shape for the bank account).
 */
function aedSeedDistributor(): array
{
    $user = User::create([
        'email' => 'd'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'full_name' => 'Original Name',
        'date_of_birth' => '1990-01-15',
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '1234',
            'aadhaar_ref' => 'STUB_REF_ABC',
            'aadhaar_last4' => '9012',
            'bank_account_enc' => Crypt::encryptString('111122223333'),
            'bank_ifsc' => 'SBIN0000001',
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
        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id' => $id, 'placement_parent_id' => $id,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);

    return ['user' => $user, 'distributor_id' => $id];
}

function aedAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'email' => 'admin-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('AED-01: admin updates full_name, phone, email — audit log diff written', function (): void {
    [$user, $id] = array_values(aedSeedDistributor());
    $admin = aedAdmin();

    $response = $this->actingAs($admin)->withoutMiddleware(PreventRequestForgery::class)->patch(route('admin.distributors.update', $id), [
        'full_name' => 'Updated Name',
        'phone_e164' => '+919998887777',
        'email' => 'new-email@test.com',
        'date_of_birth' => '1990-01-15',
        'state' => 'KA',
        'bank_ifsc' => 'HDFC0001234',
    ]);

    $response->assertRedirect(route('admin.distributors.show', $id));

    $user->refresh();
    expect($user->full_name)->toBe('Updated Name')
        ->and($user->phone_e164)->toBe('+919998887777')
        ->and($user->email)->toBe('new-email@test.com');

    $row = DB::table('distributors')->where('id', $id)->first();
    expect($row->state)->toBe('KA')
        ->and($row->bank_ifsc)->toBe('HDFC0001234');

    $audit = AuditLog::where('action', 'admin.distributor.updated')
        ->where('subject_id', $id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id);

    $changes = $audit->details['changes'] ?? [];
    // toEqual (not toBe): `details` is a JSON column and MySQL normalises JSON
    // object key order by key length ("to" before "from"), so a strict,
    // order-sensitive assertSame would pass on SQLite but fail on MySQL. The
    // from/to VALUES are what matter, not their stored key order.
    expect($changes)->toHaveKey('full_name')
        ->and($changes['full_name'])->toEqual(['from' => 'Original Name', 'to' => 'Updated Name'])
        ->and($changes)->toHaveKey('email')
        ->and($changes['email']['to'])->toBe('new-email@test.com');
});

it('AED-02: admin cannot edit ADN, PAN, Aadhaar, sponsor or placement even if posted', function (): void {
    [$user, $id] = array_values(aedSeedDistributor());
    $admin = aedAdmin();

    $original = DB::table('distributors')->where('id', $id)->first();

    $this->actingAs($admin)->withoutMiddleware(PreventRequestForgery::class)->patch(route('admin.distributors.update', $id), [
        // Allowed fields
        'phone_e164' => $user->phone_e164,
        'email' => $user->email,
        'state' => 'KA',
        'bank_ifsc' => 'HDFC0001234',
        // Locked fields — must be silently dropped
        'adn' => 'HACK_ADN',
        'pan_last4' => '0000',
        'pan_hash' => str_repeat('0', 64),
        'aadhaar_last4' => '0000',
        'aadhaar_ref' => 'HACK',
        'sponsor_id' => 99999,
        'placement_parent_id' => 99999,
        'placement_side' => 'L',
        'cooling_off_end_at' => '2030-01-01',
    ])->assertRedirect();

    $after = DB::table('distributors')->where('id', $id)->first();
    expect($after->adn)->toBe($original->adn)
        ->and($after->pan_last4)->toBe($original->pan_last4)
        ->and($after->pan_hash)->toEqual($original->pan_hash)
        ->and($after->aadhaar_last4)->toBe($original->aadhaar_last4)
        ->and($after->aadhaar_ref)->toBe($original->aadhaar_ref)
        ->and((int) $after->sponsor_id)->toBe((int) $original->sponsor_id)
        ->and((int) $after->placement_parent_id)->toBe((int) $original->placement_parent_id)
        ->and($after->placement_side)->toBe($original->placement_side)
        ->and($after->cooling_off_end_at)->toBe($original->cooling_off_end_at);
});

it('AED-03: admin can rotate bank account; new value stored encrypted; audit shows redacted last-4 only', function (): void {
    [$user, $id] = array_values(aedSeedDistributor());
    $admin = aedAdmin();

    $newAccount = '987654321099';

    $this->actingAs($admin)->withoutMiddleware(PreventRequestForgery::class)->patch(route('admin.distributors.update', $id), [
        'phone_e164' => $user->phone_e164,
        'email' => $user->email,
        'state' => 'TS',
        'bank_ifsc' => 'SBIN0000001',
        'bank_account' => $newAccount,
    ])->assertRedirect();

    $enc = DB::table('distributors')->where('id', $id)->value('bank_account_enc');
    expect($enc)->not->toBeNull();
    // Must be decryptable to the new account number.
    expect(Crypt::decryptString($enc))->toBe($newAccount);

    $audit = AuditLog::where('action', 'admin.distributor.updated')
        ->where('subject_id', $id)->latest('id')->first();
    expect($audit)->not->toBeNull();

    $bankDiff = $audit->details['changes']['bank_account'] ?? null;
    expect($bankDiff)->toBeArray()
        ->and($bankDiff['to_redacted'])->toBe('****'.substr($newAccount, -4))
        // Plaintext must NEVER appear in the audit log.
        ->and(json_encode($audit->details))->not->toContain($newAccount);
});

it('AED-04: non-admin user gets 403 on edit endpoints', function (): void {
    [$user, $id] = array_values(aedSeedDistributor());

    // The distributor's own user is NOT an admin.
    $this->actingAs($user)
        ->get(route('admin.distributors.edit', $id))
        ->assertForbidden();

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->patch(route('admin.distributors.update', $id), [
            'phone_e164' => '+919998887777',
            'email' => 'x@y.com',
            'state' => 'KA',
            'bank_ifsc' => 'HDFC0001234',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.password-reset', $id))
        ->assertForbidden();
});

it('AED-05: send-password-reset triggers RequestPasswordReset and writes audit log', function (): void {
    Notification::fake();

    [$user, $id] = array_values(aedSeedDistributor());
    $admin = aedAdmin();

    $response = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.password-reset', $id));
    $response->assertRedirect();

    // Notification dispatched to the distributor's user account.
    Notification::assertSentTo($user, PasswordResetNotification::class);

    $audit = AuditLog::where('action', 'admin.distributor.password_reset_sent')
        ->where('subject_id', $user->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->details['email'] ?? null)->toBe($user->email);
});
