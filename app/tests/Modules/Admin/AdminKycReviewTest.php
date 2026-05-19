<?php

declare(strict_types=1);

use App\Modules\Admin\Events\KycApproved;
use App\Modules\Admin\Events\KycRejected;
use App\Modules\Admin\Services\ApproveKycSubmission;
use App\Modules\Admin\Services\Exceptions\KycHasNoDocumentsError;
use App\Modules\Admin\Services\RejectKycSubmission;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * KYC manual-review approval flow:
 *  - Approve flips users.status to 'active' AND stamps verified_at on every
 *    pending kyc_documents row, all in one transaction with audit + event.
 *  - Reject flips status to 'terminated' with the admin's reason in audit_log.
 *  - Approve refuses to run on a distributor with no kyc rows (cannot
 *    rubber-stamp a distributor that uploaded nothing).
 */
function akrSeedDistributorPending(): array
{
    $user = User::create([
        'email' => 'd'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'pending',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ARO'.rand(100000, 999999),
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

function akrSeedDocuments(int $distributorId, array $types = ['pan', 'aadhaar', 'cheque']): void
{
    foreach ($types as $type) {
        KycDocument::create([
            'distributor_id' => $distributorId,
            'type' => $type,
            'object_storage_key' => "user_99/{$type}_test.jpg",
            'checksum_sha256' => str_repeat("\xAA", 32),
        ]);
    }
}

function akrAdmin(): User
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

it('AKR-01: approve flips user.status to active, stamps verified_at on docs, events + audit', function () {
    Event::fake();
    // Approve now purges PAN/Aadhaar files via Storage::disk('kyc') as part
    // of the same transaction; fake the disk so the S3 adapter isn't built.
    Storage::fake('kyc');

    [$user, $id] = array_values(akrSeedDistributorPending());
    akrSeedDocuments($id);
    $admin = akrAdmin();

    app(ApproveKycSubmission::class)($id, $admin->id);

    $user->refresh();
    expect($user->status)->toBe('active');

    // PAN + Aadhaar rows are purged by approval (AKR-05 covers that path);
    // any non-id docs (e.g. cheque) survive with verified_at stamped.
    $docs = KycDocument::where('distributor_id', $id)->get();
    expect($docs)->not->toBeEmpty();
    foreach ($docs as $doc) {
        expect($doc->verified_at)->not->toBeNull()
            ->and($doc->verifier_id)->toBe($admin->id)
            ->and($doc->type)->not->toBeIn(['pan', 'aadhaar']);
    }

    Event::assertDispatched(KycApproved::class, fn ($e) => $e->distributorId === $id);

    $audit = AuditLog::where('action', 'admin.kyc.approved')
        ->where('subject_id', $id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id);
});

it('AKR-02: approve refuses when distributor has zero kyc rows', function () {
    [$user, $id] = array_values(akrSeedDistributorPending());
    $admin = akrAdmin();

    expect(fn () => app(ApproveKycSubmission::class)($id, $admin->id))
        ->toThrow(KycHasNoDocumentsError::class);

    $user->refresh();
    expect($user->status)->toBe('pending');
});

it('AKR-03: reject flips user.status to terminated with reason in audit', function () {
    Event::fake();

    [$user, $id] = array_values(akrSeedDistributorPending());
    akrSeedDocuments($id);
    $admin = akrAdmin();

    app(RejectKycSubmission::class)($id, $admin->id, reason: 'Aadhaar image is unreadable.');

    $user->refresh();
    expect($user->status)->toBe('terminated');

    $audit = AuditLog::where('action', 'admin.kyc.rejected')
        ->where('subject_id', $id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->details['reason'] ?? null)->toBe('Aadhaar image is unreadable.');

    Event::assertDispatched(KycRejected::class);
});

it('AKR-05: approve purges PAN/Aadhaar files + nulls encrypted columns; non-id docs untouched', function () {
    Storage::fake('kyc');

    [$user, $id] = array_values(akrSeedDistributorPending());
    $admin = akrAdmin();

    // Seed three KYC docs: pan + aadhaar are the privacy-sensitive pair that
    // gets purged; cheque is left alone so we can prove the surgical scope.
    foreach (['pan', 'aadhaar', 'cheque'] as $type) {
        Storage::disk('kyc')->put("user_{$id}/{$type}_test.jpg", 'stub-bytes');
        KycDocument::create([
            'distributor_id' => $id,
            'type' => $type,
            'object_storage_key' => "user_{$id}/{$type}_test.jpg",
            'checksum_sha256' => str_repeat("\xAA", 32),
        ]);
    }

    // Pre-populate the encrypted columns so we can assert they get nulled.
    DB::table('distributors')->where('id', $id)->update([
        'pan_encrypted' => Crypt::encryptString('ABCDE1234F'),
        'aadhaar_encrypted' => Crypt::encryptString('123456789012'),
    ]);

    app(ApproveKycSubmission::class)($id, $admin->id);

    // PAN + Aadhaar files gone from S3.
    Storage::disk('kyc')->assertMissing("user_{$id}/pan_test.jpg");
    Storage::disk('kyc')->assertMissing("user_{$id}/aadhaar_test.jpg");
    // Cheque still present.
    Storage::disk('kyc')->assertExists("user_{$id}/cheque_test.jpg");

    // PAN + Aadhaar kyc_documents rows are gone; cheque survives.
    expect(KycDocument::where('distributor_id', $id)->where('type', 'pan')->exists())->toBeFalse()
        ->and(KycDocument::where('distributor_id', $id)->where('type', 'aadhaar')->exists())->toBeFalse()
        ->and(KycDocument::where('distributor_id', $id)->where('type', 'cheque')->exists())->toBeTrue();

    // Encrypted columns nulled — last-4 only remains as the on-disk number.
    $row = DB::table('distributors')->where('id', $id)
        ->select('pan_encrypted', 'aadhaar_encrypted', 'pan_last4')
        ->first();
    expect($row->pan_encrypted)->toBeNull()
        ->and($row->aadhaar_encrypted)->toBeNull()
        ->and($row->pan_last4)->toBe('0000'); // unchanged from seed

    // Audit log captures what was purged.
    $audit = AuditLog::where('action', 'admin.kyc.approved')
        ->where('subject_id', $id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->details['encrypted_numbers_nulled'] ?? 0)->toBeGreaterThan(0)
        ->and(count($audit->details['purged_files'] ?? []))->toBe(2);
});

it('AKR-06: distributor.pan_masked / aadhaar_masked return XXXXXX-prefixed strings', function () {
    [$user, $id] = array_values(akrSeedDistributorPending());

    DB::table('distributors')->where('id', $id)->update([
        'pan_last4' => '234F',
        'aadhaar_last4' => '9012',
    ]);

    /** @var Distributor $d */
    $d = Distributor::query()->findOrFail($id);

    expect($d->pan_masked)->toBe('XXXXXX234F')
        ->and($d->aadhaar_masked)->toBe('XXXX XXXX 9012');
});

it('AKR-04: admin queue lists pending distributors only', function () {
    [$pendingUser, $pendingId] = array_values(akrSeedDistributorPending());
    akrSeedDocuments($pendingId);

    [$activeUser, $activeId] = array_values(akrSeedDistributorPending());
    akrSeedDocuments($activeId);
    $activeUser->update(['status' => 'active']);

    $admin = akrAdmin();
    $response = $this->actingAs($admin)->get('/admin/kyc');
    $response->assertOk();

    $pendingAdn = DB::table('distributors')->where('id', $pendingId)->value('adn');
    $activeAdn = DB::table('distributors')->where('id', $activeId)->value('adn');

    $response->assertSee($pendingAdn);
    $response->assertDontSee($activeAdn);
});
