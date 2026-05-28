<?php

declare(strict_types=1);

use App\Modules\Admin\Services\ApproveKycSubmission;
use App\Modules\Compliance\Services\CancelCoolingOff;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RegistrationService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Couple registration (US-1.13) — option A: two distributor rows, both
 * with full KYC, mutually linked. Secondary's ADN is derived as
 * "<primary>-S" and is internal-only. Secondary doesn't take a binary
 * tree slot (placement_side = NULL, no closure rows).
 *
 * Hard rule #6 (one PAN = one couple) is preserved because the secondary's
 * PAN is checked at step 4 against both columns of `distributors`.
 */
function crSeedRoot(int $userId): int
{
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => 'ROOT'.rand(100000, 999999),
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

    return $id;
}

function crSeedSettings(): void
{
    // ADR-0003 removed placement.* settings — placement is invariant now.
    // Kept as a no-op for backward compatibility with existing test calls.
}

function crUser(string $tag): User
{
    return User::create([
        'email' => "{$tag}-".rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('correcthorsebatterystaple'),
        // Mirrors handleAccount() — a user who set their own password
        // at registration is "activated" by definition. The spouse user
        // created by RegistrationService::createSecondaryDistributor is
        // the only path that leaves password_set_at NULL.
        'password_set_at' => now(),
        'status' => 'pending',
    ]);
}

function crService(): RegistrationService
{
    return new RegistrationService(
        new PlacementEngine(
            app(DatabaseManager::class),
            app(Dispatcher::class),
        ),
        app(DatabaseManager::class),
    );
}

/**
 * Builds a couple wizard payload. Documents block is the post-step-7 shape
 * (already-uploaded paths + sha256). Spouse documents are the same shape,
 * keyed under wizardData.spouse.documents.
 *
 * @return array<string, mixed>
 */
function crPayload(int $rootId, string $primaryPan, string $spousePan, string $spouseEmail): array
{
    $docs = [
        'pan' => ['path' => 'user_x/pan.jpg', 'sha256' => str_repeat('a', 64)],
        'aadhaar' => ['path' => 'user_x/aadhaar.jpg', 'sha256' => str_repeat('b', 64)],
        'cheque' => ['path' => 'user_x/cheque.jpg', 'sha256' => str_repeat('c', 64)],
        'address_proof_front' => ['path' => 'user_x/addr_f.jpg', 'sha256' => str_repeat('d', 64)],
        'address_proof_back' => ['path' => 'user_x/addr_b.jpg', 'sha256' => str_repeat('e', 64)],
    ];

    return [
        'sponsor_id' => $rootId,
        'personal' => ['state' => 'TS', 'date_of_birth' => '1990-01-01', 'address' => '1 Main Rd'],
        'pan' => ['pan_number' => $primaryPan],
        'aadhaar' => ['ref' => 'AADH-REF-PRIMARY', 'last4' => '1111'],
        'bank' => ['account_number' => '912345678012', 'ifsc' => 'HDFC0001234'],
        'placement' => [],
        'consent' => ['ip' => '127.0.0.1', 'user_agent' => 'pest'],
        'orientation' => [],
        'documents' => $docs,
        // Couple block — present only when the wizard captured one
        'couple' => [
            'enabled' => true,
            'spouse_full_name' => 'Test Spouse',
            'spouse_dob' => '1991-02-02',
            'spouse_email' => $spouseEmail,
            'spouse_phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
            'spouse_pan_number' => $spousePan,
            'spouse_aadhaar_last4' => '2222',
            'spouse_aadhaar_ref' => 'AADH-REF-SPOUSE',
            'spouse_documents' => [
                'pan' => ['path' => 'user_y/pan.jpg', 'sha256' => str_repeat('1', 64)],
                'aadhaar' => ['path' => 'user_y/aadhaar.jpg', 'sha256' => str_repeat('2', 64)],
            ],
        ],
    ];
}

it('CR-01: couple registration creates two distributor rows mutually linked', function () {
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);

    $primaryUser = crUser('primary');

    crService()->finalise(crPayload($rootId, 'ABCDE1234F', 'PQRSE5678G', 'spouse-a@test.com'), $primaryUser);

    $primary = DB::table('distributors')->where('user_id', $primaryUser->id)->first();
    expect($primary)->not->toBeNull()
        ->and($primary->is_primary_couple)->toBe(1)
        ->and($primary->spouse_distributor_id)->not->toBeNull();

    $secondary = DB::table('distributors')->where('id', $primary->spouse_distributor_id)->first();
    expect($secondary)->not->toBeNull()
        ->and($secondary->is_primary_couple)->toBe(0)
        ->and($secondary->spouse_distributor_id)->toBe((int) $primary->id)
        ->and($secondary->adn)->toBe($primary->adn.'-S')
        ->and($secondary->placement_side)->toBeNull();
});

it('CR-02: secondary does NOT appear in genealogy_closure or sponsorship', function () {
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);
    $primaryUser = crUser('primary');

    crService()->finalise(crPayload($rootId, 'ABCDE1234A', 'PQRSE5678B', 'spouse-b@test.com'), $primaryUser);

    $primary = DB::table('distributors')->where('user_id', $primaryUser->id)->first();
    $secondaryId = (int) $primary->spouse_distributor_id;

    expect(DB::table('genealogy_closure')->where('descendant_id', $secondaryId)->count())->toBe(0);
    expect(DB::table('genealogy_closure')->where('ancestor_id', $secondaryId)->count())->toBe(0);
    expect(DB::table('sponsorship')->where('distributor_id', $secondaryId)->count())->toBe(0);
});

it('CR-03: each spouse gets their own user record + own consent + own cooling-off event', function () {
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);
    $primaryUser = crUser('primary');

    crService()->finalise(crPayload($rootId, 'ABCDE1234C', 'PQRSE5678D', 'spouse-c@test.com'), $primaryUser);

    $primary = DB::table('distributors')->where('user_id', $primaryUser->id)->first();
    $secondary = DB::table('distributors')->where('id', $primary->spouse_distributor_id)->first();

    // distinct user accounts
    expect((int) $secondary->user_id)->not->toBe((int) $primary->user_id);
    $spouseUser = DB::table('users')->where('id', $secondary->user_id)->first();
    expect($spouseUser->email)->toBe('spouse-c@test.com');

    // 4 consent rows per distributor = 8 total
    expect(DB::table('consents')->where('distributor_id', $primary->id)->count())->toBe(4);
    expect(DB::table('consents')->where('distributor_id', $secondary->id)->count())->toBe(4);

    // both have cooling-off events
    expect(DB::table('cooling_off_events')->where('distributor_id', $primary->id)->count())->toBe(1);
    expect(DB::table('cooling_off_events')->where('distributor_id', $secondary->id)->count())->toBe(1);
});

it('CR-04: spouse PAN is encrypted-context-safe (recorded as hash + last4 only)', function () {
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);
    $primaryUser = crUser('primary');

    $spousePan = 'PQRSE5678X';
    crService()->finalise(crPayload($rootId, 'ABCDE1234X', $spousePan, 'spouse-d@test.com'), $primaryUser);

    $primary = DB::table('distributors')->where('user_id', $primaryUser->id)->first();
    $secondary = DB::table('distributors')->where('id', $primary->spouse_distributor_id)->first();

    expect($secondary->pan_last4)->toBe('678X');
    // shared bank with primary — secondary's encrypted bank decrypts to the same value
    expect(Crypt::decryptString($secondary->bank_account_enc))->toBe('912345678012');
});

it('CR-05: solo registration (no couple block) leaves spouse_distributor_id NULL', function () {
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);
    $primaryUser = crUser('solo');

    $payload = crPayload($rootId, 'ABCDE1234S', 'PQRSE5678Z', 'unused@test.com');
    unset($payload['couple']);

    crService()->finalise($payload, $primaryUser);

    $primary = DB::table('distributors')->where('user_id', $primaryUser->id)->first();
    expect($primary->is_primary_couple)->toBe(0)
        ->and($primary->spouse_distributor_id)->toBeNull();
    expect(DB::table('distributors')->count())->toBe(2); // root + primary, no secondary
});

it('CR-07: admin Approve KYC on a couple flips BOTH spouses to active', function () {
    Storage::fake('kyc'); // approve purges PAN/Aadhaar files via the kyc disk
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);

    $primaryUser = crUser('primary-app');
    crService()->finalise(
        crPayload($rootId, 'ABCDE1234J', 'PQRSE5678K', 'spouse-k@test.com'),
        $primaryUser,
    );

    $primary = DB::table('distributors')->where('user_id', $primaryUser->id)->first();
    $secondary = DB::table('distributors')->where('id', $primary->spouse_distributor_id)->first();

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = crUser('admin-cr');
    $admin->assignRole('admin');

    app(ApproveKycSubmission::class)((int) $primary->id, $admin->id);

    expect(DB::table('users')->where('id', $primary->user_id)->value('status'))->toBe('active');
    expect(DB::table('users')->where('id', $secondary->user_id)->value('status'))->toBe('active');
});

it('CR-08: admin Approve invoked on the SECONDARY still flips both', function () {
    Storage::fake('kyc');
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);

    $primaryUser = crUser('primary-sec');
    crService()->finalise(
        crPayload($rootId, 'ABCDE1234L', 'PQRSE5678M', 'spouse-m@test.com'),
        $primaryUser,
    );

    $primary = DB::table('distributors')->where('user_id', $primaryUser->id)->first();
    $secondary = DB::table('distributors')->where('id', $primary->spouse_distributor_id)->first();

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = crUser('admin-cr2');
    $admin->assignRole('admin');

    // Click approve on the secondary's id — the service redirects to primary
    app(ApproveKycSubmission::class)((int) $secondary->id, $admin->id);

    expect(DB::table('users')->where('id', $primary->user_id)->value('status'))->toBe('active');
    expect(DB::table('users')->where('id', $secondary->user_id)->value('status'))->toBe('active');
});

it('CR-11: spouse user starts with password_set_at NULL and cannot log in until activated', function () {
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);

    $primaryUser = crUser('p-act');
    crService()->finalise(
        crPayload($rootId, 'ABCDE1234X', 'PQRSE5678Y', 'act-y@test.com'),
        $primaryUser,
    );

    $primary = DB::table('distributors')->where('user_id', $primaryUser->id)->first();
    $secondary = DB::table('distributors')->where('id', $primary->spouse_distributor_id)->first();
    $spouse = DB::table('users')->where('id', $secondary->user_id)->first();

    expect($spouse->password_set_at)->toBeNull();

    // Login attempt is refused at the LoginController gate.
    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/login', ['login' => $spouse->email, 'password' => 'whatever']);
    $response->assertSessionHasErrors('login');
    $errors = session('errors')->getBag('default')->get('login');
    expect(implode(' ', $errors))->toContain('not been activated');

    // Primary user has password_set_at stamped.
    expect(DB::table('users')->where('id', $primaryUser->id)->value('password_set_at'))->not->toBeNull();
});

it('CR-09: cooling-off cancel by primary cascades to spouse', function () {
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);

    $primaryUser = crUser('p-cas');
    crService()->finalise(
        crPayload($rootId, 'ABCDE1234V', 'PQRSE5678W', 'cas-w@test.com'),
        $primaryUser,
    );
    $primary = DB::table('distributors')->where('user_id', $primaryUser->id)->first();
    $secondary = DB::table('distributors')->where('id', $primary->spouse_distributor_id)->first();

    app(CancelCoolingOff::class)((int) $primary->id, $primaryUser->id);

    expect(DB::table('users')->where('id', $primary->user_id)->value('status'))->toBe('terminated');
    expect(DB::table('users')->where('id', $secondary->user_id)->value('status'))->toBe('terminated');

    expect(DB::table('cooling_off_events')->where('distributor_id', $primary->id)->value('cancelled_at'))->not->toBeNull();
    expect(DB::table('cooling_off_events')->where('distributor_id', $secondary->id)->value('cancelled_at'))->not->toBeNull();
});

it('CR-10: cooling-off cancel by secondary cascades to primary', function () {
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);

    $primaryUser = crUser('p-rev');
    crService()->finalise(
        crPayload($rootId, 'ABCDE1234R', 'PQRSE5678U', 'rev-u@test.com'),
        $primaryUser,
    );
    $primary = DB::table('distributors')->where('user_id', $primaryUser->id)->first();
    $secondary = DB::table('distributors')->where('id', $primary->spouse_distributor_id)->first();

    // Cancel triggered on the secondary's distributor id; the spouse's user
    // is the actor (in real life they'd press the button on their own dashboard,
    // but Phase 1 has them logging in via admin tooling — see C-2 follow-up).
    app(CancelCoolingOff::class)((int) $secondary->id, (int) $secondary->user_id);

    expect(DB::table('users')->where('id', $primary->user_id)->value('status'))->toBe('terminated');
    expect(DB::table('users')->where('id', $secondary->user_id)->value('status'))->toBe('terminated');
});

it('CR-06: PAN dedup catches a PAN already registered as a spouse', function () {
    crSeedSettings();
    $rootUser = crUser('root');
    $rootId = crSeedRoot($rootUser->id);

    // Couple A registers — spouse uses PAN PQRSE5678Q
    crService()->finalise(
        crPayload($rootId, 'ABCDE1234P', 'PQRSE5678Q', 'spouse-q@test.com'),
        crUser('primary-a'),
    );

    // The wizard's step-4 dedup query is: SELECT * FROM distributors WHERE pan_hash = ?
    // Secondaries live in `distributors` too, so the same query catches them.
    $duplicate = hash('sha256', 'PQRSE5678Q', true);
    expect(DB::table('distributors')->where('pan_hash', $duplicate)->exists())->toBeTrue();

    // Sanity: an unrelated PAN is not in the dedup set.
    $fresh = hash('sha256', 'XYZUV9999Z', true);
    expect(DB::table('distributors')->where('pan_hash', $fresh)->exists())->toBeFalse();
});
