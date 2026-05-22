<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DraftStateService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * APR-01 .. APR-05 — admin tool that finishes a customer's
 * registration on their behalf when they emailed in the documents
 * because they couldn't upload from their device, or hit a server
 * error at the final Confirm step.
 *
 * The customer must already have an account (users row) AND an
 * active registration draft. The admin reads the draft, optionally
 * uploads any missing KYC docs, and triggers RegistrationService::
 * finalise() — same code path the customer would have run.
 */
function aprSeedReservedRoot(): int
{
    // Minimal placement target so PlacementEngine::place() can succeed.
    // A root distributor pointing at itself, no parent.
    disableTestForeignKeys();
    try {
        $user = User::create([
            'email' => 'root-'.rand(10000, 99999).'@arovolife.local',
            'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
            'password_hash' => bcrypt('placeholder'),
            'password_set_at' => null,
            'full_name' => 'Arovolife Private Limited',
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'pan_encrypted' => null,
            'aadhaar_ref' => 'RESERVED_ROOT',
            'aadhaar_last4' => '0000',
            'aadhaar_encrypted' => null,
            'bank_account_enc' => null,
            'bank_ifsc' => null,
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

function aprSeedStuckCustomer(int $rootDistributorId, array $payloadOverrides = []): array
{
    $email = 'stuck-'.rand(10000, 99999).'@test.com';
    $user = User::create([
        'email' => $email,
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Stuck Customer',
        'date_of_birth' => '1990-01-15',
        'status' => 'pending',
    ]);

    $payload = array_replace_recursive([
        'sponsor_id' => $rootDistributorId,
        'placement' => ['placement_id' => $rootDistributorId, 'side' => null],
        'pan' => ['pan_number' => 'STUCK1234F', 'last4' => '234F'],
        'aadhaar' => ['aadhaar_number' => '999988887777', 'last4' => '7777', 'ref' => 'STUB_REF_STUCK'],
        'bank' => ['account_number' => null, 'ifsc' => null],
        'personal' => ['state' => 'TG'],
        'consent' => ['accepted' => true, 'ip' => '127.0.0.1', 'user_agent' => 'test'],
        'orientation' => ['watched' => true],
        'documents' => [],
    ], $payloadOverrides);

    $drafts = app(DraftStateService::class);
    $draft = $drafts->create($user->id, $rootDistributorId, $rootDistributorId, null, $payload, 9);

    return ['user' => $user, 'draft' => $draft];
}

function aprAdmin(): User
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

it('APR-01: pending list shows stuck customers (status=pending + no distributor row)', function (): void {
    $rootId = aprSeedReservedRoot();
    ['user' => $user] = aprSeedStuckCustomer($rootId);
    $admin = aprAdmin();

    $response = $this->actingAs($admin)
        ->get(route('admin.pending-registrations.index'));

    $response->assertStatus(200);
    $response->assertSee($user->email);
    $response->assertSee('Help finish');
});

it('APR-02: finalise creates the distributor row, deletes the draft, audit-logs', function (): void {
    $rootId = aprSeedReservedRoot();
    ['user' => $user, 'draft' => $draft] = aprSeedStuckCustomer($rootId);
    $admin = aprAdmin();

    expect(DB::table('distributors')->where('user_id', $user->id)->exists())->toBeFalse();
    expect(DB::table('registration_drafts')->where('user_id', $user->id)->exists())->toBeTrue();

    $response = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.pending-registrations.finalise', $user->id));

    $response->assertRedirect();

    // Distributor row now exists.
    $d = DB::table('distributors')->where('user_id', $user->id)->first();
    expect($d)->not->toBeNull()
        ->and($d->state)->toBe('TG')
        ->and($d->pan_last4)->toBe('234F')
        ->and($d->aadhaar_last4)->toBe('7777');

    // Draft cleared.
    expect(DB::table('registration_drafts')->where('user_id', $user->id)->exists())->toBeFalse();

    // Audit row written with admin as actor.
    $audit = AuditLog::where('action', 'admin.registration.finalised_on_behalf')
        ->where('subject_id', $d->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id);
    $json = json_encode($audit->details);
    expect($json)->toContain($user->email);
});

it('APR-03: finalise refuses if PAN is missing — returns 422 with friendly error', function (): void {
    $rootId = aprSeedReservedRoot();
    ['user' => $user] = aprSeedStuckCustomer($rootId, [
        // Wipe PAN entirely so the controller's sanity check fires.
        'pan' => ['pan_number' => '', 'last4' => ''],
    ]);
    $admin = aprAdmin();

    $response = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->from(route('admin.pending-registrations.show', $user->id))
        ->post(route('admin.pending-registrations.finalise', $user->id));

    $response->assertRedirect(route('admin.pending-registrations.show', $user->id))
        ->assertSessionHasErrors('finalise');

    // No distributor row created.
    expect(DB::table('distributors')->where('user_id', $user->id)->exists())->toBeFalse();
    // No audit row.
    expect(AuditLog::where('action', 'admin.registration.finalised_on_behalf')->exists())->toBeFalse();
});

it('APR-04: finalise refuses when distributor already exists', function (): void {
    $rootId = aprSeedReservedRoot();
    ['user' => $user] = aprSeedStuckCustomer($rootId);
    $admin = aprAdmin();

    // First finalise: succeeds.
    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.pending-registrations.finalise', $user->id))
        ->assertRedirect();

    // Second finalise on same user: abort 422.
    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.pending-registrations.finalise', $user->id))
        ->assertStatus(422);
});

it('APR-05: upload-on-behalf merges files into the draft payload + audit-logs', function (): void {
    $rootId = aprSeedReservedRoot();
    ['user' => $user] = aprSeedStuckCustomer($rootId);
    $admin = aprAdmin();

    // 70-byte valid PNG (1x1 transparent) — passes the magic-byte check.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $file = \Illuminate\Http\Testing\File::create('pan.png', 1)->mimeType('image/png');
    // Force the file's bytes to a real PNG so ValidUploadedDocumentBytes
    // sees the right magic header (Illuminate\Http\Testing\File makes a
    // hollow PHP file by default).
    file_put_contents($file->getRealPath(), $png);

    \Illuminate\Support\Facades\Storage::fake('kyc');

    $response = $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.pending-registrations.upload', $user->id), [
            'pan_doc' => $file,
        ]);

    $response->assertRedirect();

    // Draft payload now has a documents.pan_doc entry.
    $draft = DB::table('registration_drafts')->where('user_id', $user->id)->first();
    $payload = json_decode(Crypt::decryptString($draft->payload_enc), true);
    expect($payload['documents'])->toHaveKey('pan_doc')
        ->and($payload['documents']['pan_doc']['uploaded_by_admin'])->toBe($admin->id);

    // Audit row.
    $audit = AuditLog::where('action', 'admin.registration.docs_uploaded_on_behalf')
        ->where('subject_id', $user->id)->first();
    expect($audit)->not->toBeNull();
    expect($audit->details['uploaded_fields'])->toContain('pan_doc');
});
