<?php

declare(strict_types=1);

use App\Modules\Admin\Events\KycApproved;
use App\Modules\Admin\Events\KycRejected;
use App\Modules\Admin\Services\ApproveKycSubmission;
use App\Modules\Admin\Services\Exceptions\KycHasNoDocumentsError;
use App\Modules\Admin\Services\RejectKycSubmission;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
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
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
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

    [$user, $id] = array_values(akrSeedDistributorPending());
    akrSeedDocuments($id);
    $admin = akrAdmin();

    app(ApproveKycSubmission::class)($id, $admin->id);

    $user->refresh();
    expect($user->status)->toBe('active');

    $docs = KycDocument::where('distributor_id', $id)->get();
    foreach ($docs as $doc) {
        expect($doc->verified_at)->not->toBeNull()
            ->and($doc->verifier_id)->toBe($admin->id);
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
