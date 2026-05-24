<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * KDS-01 .. KDS-06 — customer-facing self-service for KYC documents.
 *
 * The wizard's step 9 now requires only PAN + Aadhaar; this surface
 * is where the customer adds the optional cheque + address-proof
 * docs later from their dashboard.
 *
 * Security rules locked in:
 *  - Owner-only: only the authenticated user's distributor row is
 *    written to.
 *  - Approved docs can NOT be replaced from the customer surface
 *    (admin support flow only). Replacement of un-verified docs
 *    is allowed (e.g. customer uploaded the wrong file).
 *  - Frozen / terminated accounts cannot upload (403).
 *  - mimetypes + magic-byte + 5 MB cap still apply per upload.
 */
function kdsSeedDistributor(string $userStatus = 'pending'): array
{
    $user = User::create([
        'email' => 'kds-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'KYC Subject',
        'status' => $userStatus,
        'activated_at' => $userStatus === 'active' ? now() : null,
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'aadhaar_ref' => 'STUB_KDS_'.uniqid(),
            'aadhaar_last4' => '0000',
            'bank_account_enc' => null,
            'bank_ifsc' => null,
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
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

it('KDS-01: GET /dashboard/documents lists current docs by type with verified/pending/missing badges', function () {
    Storage::fake('kyc');
    ['user' => $user, 'distributor_id' => $id] = kdsSeedDistributor();

    // Seed one approved PAN + one pending Aadhaar so the index has
    // representative rows for both states.
    KycDocument::create([
        'distributor_id' => $id, 'type' => 'pan',
        'object_storage_key' => "user_{$user->id}/pan_seed.png",
        'checksum_sha256' => random_bytes(32),
        'verified_at' => now()->subDay(),
        'verifier_id' => $user->id, // any user_id will do for the FK
    ]);
    KycDocument::create([
        'distributor_id' => $id, 'type' => 'aadhaar',
        'object_storage_key' => "user_{$user->id}/aadhaar_seed.png",
        'checksum_sha256' => random_bytes(32),
        'verified_at' => null,
        'verifier_id' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard.documents'));

    $response->assertStatus(200);
    $response->assertSee('PAN card');
    $response->assertSee('Approved');
    $response->assertSee('Pending review');
    $response->assertSee('Cancelled cheque or passbook page');
    $response->assertSee('(optional)', false);
});

it('KDS-02: POST /dashboard/documents stores an optional doc + writes an audit row + flips no other state', function () {
    Storage::fake('kyc');
    ['user' => $user, 'distributor_id' => $id] = kdsSeedDistributor();

    $file = UploadedFile::fake()->image('cheque.jpg', 600, 400);

    $response = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('dashboard.documents.store'), [
            'type' => 'cheque',
            'document' => $file,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    // kyc_documents row was created with verified_at = NULL.
    $doc = KycDocument::where('distributor_id', $id)->where('type', 'cheque')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->verified_at)->toBeNull()
        ->and($doc->verifier_id)->toBeNull();

    // S3 object exists under the user's namespaced prefix.
    Storage::disk('kyc')->assertExists($doc->object_storage_key);
    expect($doc->object_storage_key)->toStartWith("user_{$user->id}/cheque_");

    // Audit log row recorded with the customer as actor.
    $audit = AuditLog::where('action', 'profile.kyc_document.uploaded')
        ->where('subject_id', $id)
        ->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($user->id);
});

it('KDS-03: customer cannot replace an approved document — friendly error returned', function () {
    Storage::fake('kyc');
    ['user' => $user, 'distributor_id' => $id] = kdsSeedDistributor();

    KycDocument::create([
        'distributor_id' => $id, 'type' => 'pan',
        'object_storage_key' => "user_{$user->id}/pan_approved.png",
        'checksum_sha256' => random_bytes(32),
        'verified_at' => now()->subDay(), // ← already approved by admin
        'verifier_id' => $user->id,
    ]);

    $response = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('dashboard.documents.store'), [
            'type' => 'pan',
            'document' => UploadedFile::fake()->image('pan-new.jpg', 600, 400),
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('document');
    expect(session('errors')->get('document')[0])->toContain('already approved');

    // Only the original row exists — no new pending duplicate.
    expect(KycDocument::where('distributor_id', $id)->where('type', 'pan')->count())->toBe(1);
});

it('KDS-04: replacing an UNVERIFIED doc removes the previous pending row and stores the new one', function () {
    Storage::fake('kyc');
    ['user' => $user, 'distributor_id' => $id] = kdsSeedDistributor();

    // Seed a pending Aadhaar that customer wants to replace.
    $prev = KycDocument::create([
        'distributor_id' => $id, 'type' => 'aadhaar',
        'object_storage_key' => "user_{$user->id}/aadhaar_old.png",
        'checksum_sha256' => random_bytes(32),
        'verified_at' => null,
        'verifier_id' => null,
    ]);

    $response = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('dashboard.documents.store'), [
            'type' => 'aadhaar',
            'document' => UploadedFile::fake()->image('aadhaar-new.jpg', 600, 400),
        ]);

    $response->assertRedirect();

    // Old row gone, exactly one new row in its place.
    expect(KycDocument::find($prev->id))->toBeNull();
    $current = KycDocument::where('distributor_id', $id)->where('type', 'aadhaar')->get();
    expect($current)->toHaveCount(1)
        ->and($current->first()->verified_at)->toBeNull();

    // Audit row records the replacement flag.
    $audit = AuditLog::where('action', 'profile.kyc_document.uploaded')
        ->where('subject_id', $id)
        ->latest('id')->first();
    expect($audit->details['replaced_previous'] ?? null)->toBeTrue();
});

it('KDS-05: frozen account cannot upload — 403 with friendly message', function () {
    Storage::fake('kyc');
    ['user' => $user] = kdsSeedDistributor(userStatus: 'frozen');

    $response = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('dashboard.documents.store'), [
            'type' => 'cheque',
            'document' => UploadedFile::fake()->image('cheque.jpg', 600, 400),
        ]);

    $response->assertStatus(403);
});

it('KDS-06: unsupported type values are rejected — only the canonical 5 are accepted', function () {
    Storage::fake('kyc');
    ['user' => $user] = kdsSeedDistributor();

    $response = $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('dashboard.documents.store'), [
            'type' => 'photo', // ID photo is handled by IdPhotoController, not here
            'document' => UploadedFile::fake()->image('photo.jpg', 600, 400),
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('type');
});
