<?php

declare(strict_types=1);

use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RegistrationService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Locks the contract that PII written by RegistrationService is actually
 * encrypted at rest, not stored as plaintext-with-padding (the Phase 1 stub
 * which silently shipped real bank account numbers to the database).
 *
 * If this test ever passes for the wrong reason — e.g. someone reverts to a
 * stub that still happens to round-trip — the "ciphertext != plaintext"
 * assertion catches it.
 */
function seedRoot(int $userId): int
{
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => 'ROOT'.rand(100000, 999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => random_bytes(32),
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
            'sponsor_id' => $id,
            'placement_parent_id' => $id,
        ]);
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id,
        'descendant_id' => $id,
        'depth' => 0,
    ]);

    return $id;
}

function seedSettings(): void
{
    // ADR-0003 removed placement.* settings — placement is invariant now.
    // Kept as a no-op for backward compatibility with existing test calls.
}

function makeUser(string $suffix): User
{
    return User::create([
        'email' => "buyer{$suffix}@test.com",
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('correcthorsebatterystaple'),
        'status' => 'pending',
    ]);
}

function makeService(): RegistrationService
{
    return new RegistrationService(
        new PlacementEngine(
            app(DatabaseManager::class),
            app(Dispatcher::class),
        ),
        app(DatabaseManager::class),
    );
}

it('ENC-01: bank account is encrypted at rest, never stored as plaintext', function () {
    seedSettings();
    $rootUser = makeUser('root');
    $rootId = seedRoot($rootUser->id);

    $applicant = makeUser('applicant');
    $plaintext = '912345678012';

    makeService()->finalise([
        'sponsor_id' => $rootId,
        'personal' => ['state' => 'TS', 'date_of_birth' => '1990-01-01'],
        'pan' => ['pan_number' => 'ABCDE1234F'],
        'aadhaar' => ['ref' => 'AADH-REF-XYZ', 'last4' => '0001'],
        'bank' => ['account_number' => $plaintext, 'ifsc' => 'HDFC0001234'],
        'placement' => [],
        'consent' => ['ip' => '127.0.0.1', 'user_agent' => 'pest'],
        'orientation' => [],
    ], $applicant);

    $stored = DB::table('distributors')
        ->where('user_id', $applicant->id)
        ->value('bank_account_enc');

    expect($stored)->not->toBeNull('bank_account_enc must be persisted')
        ->and($stored)->not->toContain($plaintext, 'plaintext must not appear in ciphertext')
        ->and(Crypt::decryptString($stored))->toBe($plaintext, 'ciphertext must round-trip via Laravel Crypt');
});

it('ENC-02: full PAN + Aadhaar are persisted encrypted; ciphertext does not contain plaintext', function () {
    // Pre-purge state — what we hold while admin reviews the upload. After
    // KYC verification, pan_encrypted / aadhaar_encrypted are nulled
    // (see AKR-05); this test exclusively covers the pre-verify window.
    seedSettings();
    $rootUser = makeUser('root');
    $rootId = seedRoot($rootUser->id);

    $applicant = makeUser('applicant-pii');
    $panPlain = 'ABCDE1234F';
    $aadhaarPlain = '123456789012';

    makeService()->finalise([
        'sponsor_id' => $rootId,
        'personal' => ['state' => 'TS', 'date_of_birth' => '1990-01-01'],
        'pan' => ['pan_number' => $panPlain],
        'aadhaar' => ['aadhaar_number' => $aadhaarPlain, 'ref' => 'AADH-REF-XYZ'],
        'bank' => ['account_number' => '999999999', 'ifsc' => 'HDFC0001234'],
        'placement' => [],
        'consent' => ['ip' => '127.0.0.1', 'user_agent' => 'pest'],
        'orientation' => [],
    ], $applicant);

    $row = DB::table('distributors')
        ->where('user_id', $applicant->id)
        ->select('pan_encrypted', 'aadhaar_encrypted', 'pan_last4', 'aadhaar_last4')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->pan_encrypted)->not->toBeNull('pan_encrypted must be persisted')
        ->and($row->pan_encrypted)->not->toContain($panPlain, 'PAN plaintext must not appear in ciphertext')
        ->and(Crypt::decryptString($row->pan_encrypted))->toBe($panPlain)
        ->and($row->pan_last4)->toBe('234F')
        ->and($row->aadhaar_encrypted)->not->toBeNull('aadhaar_encrypted must be persisted')
        ->and($row->aadhaar_encrypted)->not->toContain($aadhaarPlain, 'Aadhaar plaintext must not appear in ciphertext')
        ->and(Crypt::decryptString($row->aadhaar_encrypted))->toBe($aadhaarPlain)
        ->and($row->aadhaar_last4)->toBe('9012');
});
