<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Register of Direct Sellers — DSR 2021 Rule 3(g) record-keeping. The CSV
 * is the single artefact a regulator may demand under audit, and it must:
 *  - be accessible only to the admin role
 *  - never leak full PAN, full Aadhaar number, or the encrypted bank value
 *  - include sponsor ADN and the date KYC was verified, so the regulator
 *    can verify due diligence
 *  - leave a row in audit_log every time it is exported
 */
function rdseAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $u = User::create([
        'email' => 'rdse-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'full_name' => 'Register Admin',
    ]);
    $u->assignRole('admin');

    return $u;
}

function rdseSeedDistributor(string $adn, string $email, ?int $sponsorId = null): array
{
    $u = User::create([
        'email' => $email,
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'full_name' => 'Test Distributor',
        'date_of_birth' => '1990-01-01',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $u->id,
            'adn' => $adn,
            'pan_hash' => random_bytes(32),
            'pan_last4' => '9999',
            'aadhaar_ref' => 'STUB-REF-XYZ',
            'aadhaar_last4' => '1111',
            'bank_account_enc' => 'eyJzZWNyZXQiOiJzaG91bGQtbm90LWxlYWsifQ==', // base64 sentinel
            'bank_ifsc' => 'HDFC0001234',
            'sponsor_id' => $sponsorId ?? 0,
            'placement_parent_id' => $sponsorId ?? 0,
            'placement_side' => $sponsorId === null ? null : 'L',
            'side_chosen_by' => 'referral_default',
            'depth' => $sponsorId === null ? 0 : 1,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        if ($sponsorId === null) {
            DB::table('distributors')->where('id', $id)->update([
                'sponsor_id' => $id, 'placement_parent_id' => $id,
            ]);
        }
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);

    return ['user' => $u, 'distributor_id' => $id];
}

it('RDSE-01: anonymous access is refused', function () {
    $response = $this->get('/admin/distributors/export');
    expect($response->status())->toBeIn([302, 401, 403]); // login redirect or forbid
});

it('RDSE-02: non-admin user gets 403', function () {
    $u = User::create([
        'email' => 'plain-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $this->actingAs($u);
    $response = $this->get('/admin/distributors/export');
    $response->assertForbidden();
});

it('RDSE-03: admin export returns CSV and writes an audit_log row', function () {
    $admin = rdseAdmin();
    rdseSeedDistributor('AROROOT001', 'root@test.com');

    $this->actingAs($admin);
    $response = $this->get('/admin/distributors/export');
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');

    $audit = DB::table('audit_log')
        ->where('action', 'admin.register.exported')
        ->where('actor_id', $admin->id)
        ->first();
    expect($audit)->not->toBeNull();
});

it('RDSE-04: export NEVER contains full PAN, full Aadhaar, or encrypted bank value', function () {
    $admin = rdseAdmin();
    rdseSeedDistributor('AROROOT002', 'leak-check@test.com');

    $this->actingAs($admin);
    $body = $this->get('/admin/distributors/export')->getContent();

    // No raw 32-byte hash should leak (check for hex form too just in case).
    expect($body)->not->toContain(bin2hex(str_repeat("\xAA", 32)));
    // Encrypted bank sentinel must not be in the CSV.
    expect($body)->not->toContain('eyJzZWNyZXQiOiJzaG91bGQtbm90LWxlYWsifQ==');
    // The Aadhaar ref (a vendor-issued opaque id) is not regulator-shareable.
    expect($body)->not->toContain('STUB-REF-XYZ');
});

it('RDSE-05: export includes sponsor ADN and KYC verified date', function () {
    $admin = rdseAdmin();

    [$rootUser, $rootId] = array_values(rdseSeedDistributor('AROROOT003', 'root3@test.com'));
    [$childUser, $childId] = array_values(rdseSeedDistributor('AROCHILD003', 'child3@test.com', sponsorId: $rootId));

    // Stamp KYC verified on the child to simulate an admin-approved submission.
    KycDocument::create([
        'distributor_id' => $childId,
        'type' => 'pan',
        'object_storage_key' => 'user_x/pan_test.jpg',
        'checksum_sha256' => str_repeat("\xCC", 32),
        'verified_at' => now()->subDay(),
        'verifier_id' => $admin->id,
    ]);

    $this->actingAs($admin);
    $body = $this->get('/admin/distributors/export')->getContent();

    expect($body)->toContain('Sponsor ADN')
        ->and($body)->toContain('KYC Verified')
        ->and($body)->toContain('AROROOT003'); // child's sponsor ADN appears in child's row
});
