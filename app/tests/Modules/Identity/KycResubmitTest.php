<?php

declare(strict_types=1);

use App\Modules\Admin\Services\RejectKycSubmission;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Events\KycResubmitted;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ResubmitKycSubmission;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * KRS — KYC re-submission flow tests.
 *
 * Covers the recovery path that a previously rejected applicant takes when
 * they upload corrected documents. The service must replace existing
 * unverified docs, flip status='rejected' → 'pending', record an audit
 * entry, and dispatch the KycResubmitted event.
 *
 * Couple registrations are treated as a unit: when the primary resubmits,
 * both spouses' user.status flips back to pending so the admin can approve
 * the unit again.
 */

function krsSeedDistributor(string $status = 'pending', bool $isPrimaryCouple = false, ?int $spouseDistId = null): array
{
    $user = User::create([
        'email' => 'krs-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => $status,
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'aadhaar_ref' => 'STUB_'.uniqid(),
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
            'is_primary_couple' => $isPrimaryCouple ? 1 : 0,
            'spouse_distributor_id' => $spouseDistId,
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

function krsSeedDocs(int $distributorId, array $types = ['pan', 'aadhaar']): void
{
    foreach ($types as $type) {
        KycDocument::create([
            'distributor_id' => $distributorId,
            'type' => $type,
            'object_storage_key' => "user_99/{$type}_old.jpg",
            'checksum_sha256' => str_repeat("\xAA", 32),
        ]);
    }
}

function krsAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::create([
        'email' => 'admin-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('KRS-01: resubmit flips status rejected→pending and replaces old documents', function (): void {
    Storage::fake('kyc');
    Event::fake();

    // Set up a rejected distributor with old docs.
    [$user, $id] = array_values(krsSeedDistributor('rejected'));
    krsSeedDocs($id, ['pan', 'aadhaar']);

    $newFile = UploadedFile::fake()->create('pan_new.jpg', 200, 'image/jpeg');

    app(ResubmitKycSubmission::class)($id, ['pan' => $newFile]);

    // Status: rejected → pending
    $user->refresh();
    expect($user->status)->toBe('pending');

    // PAN doc replaced (only one row for that type)
    $panDocs = KycDocument::where('distributor_id', $id)->where('type', 'pan')->get();
    expect($panDocs)->toHaveCount(1);
    expect($panDocs->first()->object_storage_key)->not->toBe('user_99/pan_old.jpg');

    // Aadhaar untouched (we only uploaded PAN replacement)
    $aadhaarDocs = KycDocument::where('distributor_id', $id)->where('type', 'aadhaar')->get();
    expect($aadhaarDocs)->toHaveCount(1);
    expect($aadhaarDocs->first()->object_storage_key)->toBe('user_99/aadhaar_old.jpg');

    // Audit entry
    $audit = AuditLog::where('action', 'kyc.resubmitted')->where('subject_id', $id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->details['document_types'])->toBe(['pan']);

    // Event dispatched for downstream email listeners
    Event::assertDispatched(KycResubmitted::class);
});

it('KRS-02: resubmit refuses when no files provided', function (): void {
    [$user, $id] = array_values(krsSeedDistributor('rejected'));

    expect(fn () => app(ResubmitKycSubmission::class)($id, []))
        ->toThrow(InvalidArgumentException::class);

    $user->refresh();
    expect($user->status)->toBe('rejected'); // unchanged
});

it('KRS-03: resubmit only flips status when current status is rejected', function (): void {
    Storage::fake('kyc');
    Event::fake();

    // Distributor whose status is somehow 'active' (shouldn't happen in
    // practice — middleware blocks them — but defend at the service level).
    [$user, $id] = array_values(krsSeedDistributor('active'));
    $newFile = UploadedFile::fake()->create('pan_new.jpg', 200, 'image/jpeg');

    app(ResubmitKycSubmission::class)($id, ['pan' => $newFile]);

    $user->refresh();
    // The where('status','rejected') guard kept us at 'active' — we didn't
    // accidentally demote an approved account.
    expect($user->status)->toBe('active');
});

it('KRS-04: couple resubmit flips BOTH spouses rejected→pending', function (): void {
    Storage::fake('kyc');
    Event::fake();

    // Seed a couple: spouse first (so we can reference its id from primary).
    [$spouseUser, $spouseId] = array_values(krsSeedDistributor('rejected'));
    [$primaryUser, $primaryId] = array_values(krsSeedDistributor('rejected', isPrimaryCouple: true, spouseDistId: $spouseId));
    // Link the spouse back to the primary.
    DB::table('distributors')->where('id', $spouseId)->update([
        'spouse_distributor_id' => $primaryId,
        'is_primary_couple' => 0,
    ]);

    $newFile = UploadedFile::fake()->create('pan_new.jpg', 200, 'image/jpeg');

    // Primary submits replacement documents.
    app(ResubmitKycSubmission::class)($primaryId, ['pan' => $newFile]);

    $primaryUser->refresh();
    $spouseUser->refresh();
    expect($primaryUser->status)->toBe('pending');
    expect($spouseUser->status)->toBe('pending'); // critical: spouse came back too
});

it('KRS-05: reject-then-resubmit-then-reject is permitted (no retry cap)', function (): void {
    Storage::fake('kyc');
    Event::fake();

    // First rejection.
    [$user, $id] = array_values(krsSeedDistributor('pending'));
    krsSeedDocs($id, ['pan', 'aadhaar']);
    $admin = krsAdmin();
    app(RejectKycSubmission::class)($id, $admin->id, reason: 'PAN unreadable.');

    $user->refresh();
    expect($user->status)->toBe('rejected');

    // Applicant resubmits.
    $newFile = UploadedFile::fake()->create('pan_new.jpg', 200, 'image/jpeg');
    app(ResubmitKycSubmission::class)($id, ['pan' => $newFile]);

    $user->refresh();
    expect($user->status)->toBe('pending');

    // Admin rejects again — same reason, totally legal.
    app(RejectKycSubmission::class)($id, $admin->id, reason: 'Still unreadable.');

    $user->refresh();
    expect($user->status)->toBe('rejected');

    // Two rejection audit rows, one resubmit row.
    $rejections = AuditLog::where('action', 'admin.kyc.rejected')->where('subject_id', $id)->count();
    $resubmits = AuditLog::where('action', 'kyc.resubmitted')->where('subject_id', $id)->count();
    expect($rejections)->toBe(2);
    expect($resubmits)->toBe(1);
});
