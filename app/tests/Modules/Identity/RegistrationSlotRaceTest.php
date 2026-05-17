<?php

declare(strict_types=1);

use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\Exceptions\PlacementSlotFullError;
use App\Modules\Genealogy\Services\Exceptions\PlacementSlotsExhaustedError;
use App\Modules\Genealogy\Services\PlacementEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * C1 regression — when the placement target's slot fills between
 * RegistrationWizardController::start()'s pre-flight check and
 * RegistrationService::finalise(), the engine throws
 * PlacementSlotFullError / PlacementSlotsExhaustedError. handleComplete()
 * must catch both and route the user to /contact-us instead of producing
 * a 500. The user has already submitted PAN/Aadhaar/bank by this point.
 */
function srrSeedRoot(): int
{
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        $userId = DB::table('users')->insertGetId([
            'email' => 'srr-root-'.uniqid().'@test.com',
            'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
            'password_hash' => bcrypt('x'),
            'password_set_at' => now(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);

    return $id;
}

it('SRR-01: side=L collision raises PlacementSlotFullError, not a generic 500', function () {
    $root = srrSeedRoot();
    $engine = app(PlacementEngine::class);

    // First registration claims root.L
    $engine->place(new PlaceDistributorInput(
        userId: DB::table('users')->insertGetId([
            'email' => 'srr-a-'.uniqid().'@test.com',
            'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
            'password_hash' => bcrypt('x'),
            'password_set_at' => now(),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]),
        sponsorId: $root,
        placementId: $root,
        panHash: random_bytes(32),
        panLast4: 'AAAA',
        bankAccountEnc: 'stub',
        bankIfsc: 'SBIN0000000',
        state: 'TS',
        sideOpt: 'L',
    ));

    // Second registration targets the SAME slot — this is the C1 race.
    expect(fn () => $engine->place(new PlaceDistributorInput(
        userId: DB::table('users')->insertGetId([
            'email' => 'srr-b-'.uniqid().'@test.com',
            'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
            'password_hash' => bcrypt('x'),
            'password_set_at' => now(),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]),
        sponsorId: $root,
        placementId: $root,
        panHash: random_bytes(32),
        panLast4: 'BBBB',
        bankAccountEnc: 'stub',
        bankIfsc: 'SBIN0000000',
        state: 'TS',
        sideOpt: 'L',
    )))->toThrow(PlacementSlotFullError::class);
});

it('SRR-02: no side and both slots taken raises PlacementSlotsExhaustedError', function () {
    $root = srrSeedRoot();
    $engine = app(PlacementEngine::class);

    foreach (['L', 'R'] as $side) {
        $engine->place(new PlaceDistributorInput(
            userId: DB::table('users')->insertGetId([
                'email' => 'srr-'.$side.'-'.uniqid().'@test.com',
                'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
                'password_hash' => bcrypt('x'),
                'password_set_at' => now(),
                'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]),
            sponsorId: $root,
            placementId: $root,
            panHash: random_bytes(32),
            panLast4: $side.$side.$side.$side,
            bankAccountEnc: 'stub',
            bankIfsc: 'SBIN0000000',
            state: 'TS',
            sideOpt: $side,
        ));
    }

    expect(fn () => $engine->place(new PlaceDistributorInput(
        userId: DB::table('users')->insertGetId([
            'email' => 'srr-x-'.uniqid().'@test.com',
            'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
            'password_hash' => bcrypt('x'),
            'password_set_at' => now(),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]),
        sponsorId: $root,
        placementId: $root,
        panHash: random_bytes(32),
        panLast4: 'XXXX',
        bankAccountEnc: 'stub',
        bankIfsc: 'SBIN0000000',
        state: 'TS',
        sideOpt: null,
    )))->toThrow(PlacementSlotsExhaustedError::class);
});
