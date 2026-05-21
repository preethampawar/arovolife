<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;
use App\Modules\Shared\Features\HibpPasswordCheck;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Compliance-sensitive admin actions on the distributor edit page:
 *
 *  - PAN / Aadhaar identity update (Hard rule #6 — one PAN = one ADN;
 *    Hard rule #8 — PII encrypted at rest, last-4 only in audit logs).
 *  - Direct password set (StrongPassword + NotPwned; password never
 *    enters audit log).
 *
 * Each test seeds two reserved distributors (so the FK to placement
 * parent is satisfiable for the row we mutate) and an admin user with
 * the 'admin' role. Couple/secondary distributors are out of scope —
 * they get their own test file once that flow lands.
 */
function diaSeedDistributor(string $panLast4 = 'AAAA', ?string $panRaw = null): array
{
    $user = User::create([
        'email' => 'd'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Test Distributor',
        'date_of_birth' => '1990-01-15',
        'status' => 'active',
        'activated_at' => now(),
    ]);

    // Deterministic-ish PAN hash. When the test supplies a raw PAN
    // we hash it (so the seeded row matches a known PAN for dedup
    // testing); otherwise random bytes for an opaque seed.
    $panHash = $panRaw !== null ? hash('sha256', $panRaw, true) : random_bytes(32);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => $panHash,
            'pan_last4' => $panLast4,
            'pan_encrypted' => Crypt::encryptString($panRaw ?? 'ABCDE1234F'),
            'aadhaar_ref' => 'STUB_REF_'.uniqid(),
            'aadhaar_last4' => '9012',
            'aadhaar_encrypted' => Crypt::encryptString('123456789012'),
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

function diaSeedKycDoc(int $distributorId, string $type, ?int $verifierId = null): int
{
    // kyc_documents.type is an enum: pan, aadhaar, cheque,
    // address_proof_front, address_proof_back, photo.
    return DB::table('kyc_documents')->insertGetId([
        'distributor_id' => $distributorId,
        'type' => $type,
        'object_storage_key' => 'kyc/'.$distributorId.'/'.uniqid().'.jpg',
        'checksum_sha256' => random_bytes(32),
        'verified_at' => $verifierId !== null ? now() : null,
        'verifier_id' => $verifierId,
        'created_at' => now()->format('Y-m-d H:i:s.v'),
        'updated_at' => now()->format('Y-m-d H:i:s.v'),
    ]);
}

function diaAdmin(): User
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

// ── Identity update ──────────────────────────────────────────────────────

it('DIA-01: identity update rewrites PAN hash + last4 and re-encrypts the value', function (): void {
    ['distributor_id' => $id] = diaSeedDistributor('AAAA');
    $admin = diaAdmin();

    $newPan = 'BCDEF2345G';

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.identity', $id), [
            'pan_number' => $newPan,
        ])
        ->assertRedirect();

    $row = DB::table('distributors')->where('id', $id)->first();
    expect($row->pan_last4)->toBe(substr($newPan, -4))
        ->and($row->pan_hash)->toBe(hash('sha256', $newPan, true))
        ->and(Crypt::decryptString($row->pan_encrypted))->toBe($newPan);
});

it('DIA-02: identity update rewrites Aadhaar last4 + ref and re-encrypts the value', function (): void {
    ['distributor_id' => $id] = diaSeedDistributor();
    $admin = diaAdmin();

    $newAadhaar = '987654321012';

    $oldRef = DB::table('distributors')->where('id', $id)->value('aadhaar_ref');

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.identity', $id), [
            'aadhaar_number' => $newAadhaar,
        ])
        ->assertRedirect();

    $row = DB::table('distributors')->where('id', $id)->first();
    expect($row->aadhaar_last4)->toBe(substr($newAadhaar, -4))
        ->and(Crypt::decryptString($row->aadhaar_encrypted))->toBe($newAadhaar)
        ->and($row->aadhaar_ref)->not->toBe($oldRef);
});

it('DIA-03: PAN dedup-clash returns 422 + error and leaves both rows untouched', function (): void {
    // Seed two distributors. A has PAN_X (known via raw seed). When the
    // admin tries to set B's PAN to PAN_X, dedup must reject.
    $rawPanA = 'ABCDE1234F';
    ['distributor_id' => $idA] = diaSeedDistributor('234F', $rawPanA);
    ['distributor_id' => $idB] = diaSeedDistributor('5678');
    $admin = diaAdmin();

    $beforeA = DB::table('distributors')->where('id', $idA)->first();
    $beforeB = DB::table('distributors')->where('id', $idB)->first();

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->from(route('admin.distributors.edit', $idB))
        ->post(route('admin.distributors.identity', $idB), [
            'pan_number' => $rawPanA,
        ])
        ->assertRedirect(route('admin.distributors.edit', $idB))
        ->assertSessionHasErrors('pan_number');

    // Neither row should have changed.
    $afterA = DB::table('distributors')->where('id', $idA)->first();
    $afterB = DB::table('distributors')->where('id', $idB)->first();
    expect($afterA->pan_last4)->toBe($beforeA->pan_last4)
        ->and($afterA->pan_hash)->toBe($beforeA->pan_hash)
        ->and($afterB->pan_last4)->toBe($beforeB->pan_last4)
        ->and($afterB->pan_hash)->toBe($beforeB->pan_hash);

    // No identity_updated audit row should have been written for B.
    expect(AuditLog::where('action', 'admin.distributor.identity_updated')
        ->where('subject_id', $idB)->exists())->toBeFalse();
});

it('DIA-04: identity update resets verified KYC documents + flips user status to pending', function (): void {
    ['user' => $user, 'distributor_id' => $id] = diaSeedDistributor();
    $admin = diaAdmin();

    // Seed two approved KYC docs on this distributor — one PAN scan,
    // one Aadhaar scan. type enum: pan, aadhaar, cheque,
    // address_proof_front, address_proof_back, photo.
    $docId1 = diaSeedKycDoc($id, 'pan', $admin->id);
    $docId2 = diaSeedKycDoc($id, 'aadhaar', $admin->id);
    expect(DB::table('kyc_documents')->where('id', $docId1)->value('verified_at'))->not->toBeNull()
        ->and(DB::table('kyc_documents')->where('id', $docId2)->value('verified_at'))->not->toBeNull();
    expect($user->fresh()->status)->toBe('active');

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.identity', $id), [
            'pan_number' => 'XYZAB6789Z',
        ])
        ->assertRedirect();

    expect(DB::table('kyc_documents')->where('id', $docId1)->value('verified_at'))->toBeNull()
        ->and(DB::table('kyc_documents')->where('id', $docId1)->value('verifier_id'))->toBeNull()
        ->and(DB::table('kyc_documents')->where('id', $docId2)->value('verified_at'))->toBeNull()
        ->and($user->fresh()->status)->toBe('pending');
});

it('DIA-05: identity update audit row carries last-4 only, never the raw PAN', function (): void {
    ['distributor_id' => $id] = diaSeedDistributor('AAAA');
    $admin = diaAdmin();

    $newPan = 'WXYZA9876B';

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.identity', $id), [
            'pan_number' => $newPan,
            'aadhaar_number' => '111122223344',
        ])
        ->assertRedirect();

    $audit = AuditLog::where('action', 'admin.distributor.identity_updated')
        ->where('subject_id', $id)->first();
    expect($audit)->not->toBeNull();

    // The PHPDoc/Laravel cast on AuditLog::details should give us an
    // array — serialise it back to JSON for the substring check.
    $json = json_encode($audit->details, JSON_UNESCAPED_SLASHES);
    expect($json)->toContain('"pan_last4":"AAAA"')        // before
        ->and($json)->toContain('"pan_last4":"876B"')      // after
        ->and($json)->toContain('"aadhaar_last4":"3344"')  // after
        ->and($json)->not->toContain($newPan)              // raw PAN never appears
        ->and($json)->not->toContain('111122223344');      // raw Aadhaar never appears
});

it('DIA-06: identity update with both fields blank returns 422 — no audit row', function (): void {
    ['distributor_id' => $id] = diaSeedDistributor();
    $admin = diaAdmin();

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->from(route('admin.distributors.edit', $id))
        ->post(route('admin.distributors.identity', $id), [
            'pan_number' => '',
            'aadhaar_number' => '',
        ])
        ->assertRedirect(route('admin.distributors.edit', $id))
        ->assertSessionHasErrors('identity');

    expect(AuditLog::where('action', 'admin.distributor.identity_updated')
        ->where('subject_id', $id)->exists())->toBeFalse();
});

// ── Direct password set ──────────────────────────────────────────────────

it('DPS-01: direct password set hashes new password + sets password_set_at + audit-logs', function (): void {
    ['user' => $user, 'distributor_id' => $id] = diaSeedDistributor();
    $admin = diaAdmin();

    // HIBP off so the test password isn't blocked by the breach API in
    // CI environments without internet egress. zxcvbn still enforces
    // entropy on a true random-looking string below.
    Feature::deactivate(HibpPasswordCheck::class);

    $oldHash = $user->password_hash;
    $strongPw = 'Mango-Lotus-Forest-92a7Q';

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.set-password', $id), [
            'new_password' => $strongPw,
            'new_password_confirmation' => $strongPw,
        ])
        ->assertRedirect();

    $user->refresh();
    expect($user->password_hash)->not->toBe($oldHash)
        ->and(Hash::check($strongPw, $user->password_hash))->toBeTrue()
        ->and($user->password_set_at)->not->toBeNull();

    $audit = AuditLog::where('action', 'admin.distributor.password_set')
        ->where('subject_id', $user->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id);

    // The password (raw OR hashed) must never enter the audit log.
    $json = json_encode($audit->details);
    expect($json)->not->toContain($strongPw)
        ->and($json)->not->toContain('password_hash')
        ->and($json)->toContain($user->email)
        ->and($json)->toContain('"method":"direct"');
});

it('DPS-02: direct password set rejects mismatched confirmation', function (): void {
    ['user' => $user, 'distributor_id' => $id] = diaSeedDistributor();
    $admin = diaAdmin();
    Feature::deactivate(HibpPasswordCheck::class);

    $oldHash = $user->password_hash;

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->from(route('admin.distributors.edit', $id))
        ->post(route('admin.distributors.set-password', $id), [
            'new_password' => 'Mango-Lotus-Forest-92a7Q',
            'new_password_confirmation' => 'Apple-Orange-Banana-13b2R',
        ])
        ->assertRedirect(route('admin.distributors.edit', $id))
        ->assertSessionHasErrors('new_password_confirmation');

    expect($user->fresh()->password_hash)->toBe($oldHash);
    expect(AuditLog::where('action', 'admin.distributor.password_set')
        ->where('subject_id', $user->id)->exists())->toBeFalse();
});

it('DPS-03: direct password set rejects too-short passwords', function (): void {
    ['user' => $user, 'distributor_id' => $id] = diaSeedDistributor();
    $admin = diaAdmin();
    Feature::deactivate(HibpPasswordCheck::class);

    $shortPw = 'short';

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->from(route('admin.distributors.edit', $id))
        ->post(route('admin.distributors.set-password', $id), [
            'new_password' => $shortPw,
            'new_password_confirmation' => $shortPw,
        ])
        ->assertRedirect(route('admin.distributors.edit', $id))
        ->assertSessionHasErrors('new_password');
});

it('DPS-04: direct password set revokes any pending password_reset_tokens', function (): void {
    ['user' => $user, 'distributor_id' => $id] = diaSeedDistributor();
    $admin = diaAdmin();
    Feature::deactivate(HibpPasswordCheck::class);

    // Seed a fake pending reset token on this email so we can verify
    // setPassword() wipes it. The migration uses token_hash (sha256
    // hex of the URL token), not Laravel's vanilla `token` column.
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
        'created_at' => now()->format('Y-m-d H:i:s.v'),
    ]);
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeTrue();

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.distributors.set-password', $id), [
            'new_password' => 'Saffron-Cobalt-7821-Quartz',
            'new_password_confirmation' => 'Saffron-Cobalt-7821-Quartz',
        ])
        ->assertRedirect();

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
});
