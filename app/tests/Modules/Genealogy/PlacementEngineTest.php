<?php

declare(strict_types=1);

use App\Modules\Genealogy\Events\DistributorRegistered;
use App\Modules\Genealogy\Events\PlacementCreated;
use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\Exceptions\CrossLinePlacementError;
use App\Modules\Genealogy\Services\Exceptions\PlacementSlotFullError;
use App\Modules\Genealogy\Services\Exceptions\PlacementSlotsExhaustedError;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Services\TeamStatsService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// ─── helpers ───────────────────────────────────────────────────────────────

function makeEngine(): PlacementEngine
{
    return new PlacementEngine(
        app(DatabaseManager::class),
        app(Dispatcher::class),
        app(TeamStatsService::class),
    );
}

function seedRootDistributor(?int $userId = null, int $depth = 0): int
{
    $userId = $userId ?? seedUser();

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => random_bytes(32),
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => $depth,
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
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id,
        'descendant_id' => $id,
        'depth' => 0,
    ]);

    return $id;
}

function seedUser(): int
{
    return DB::table('users')->insertGetId([
        'email' => 'u'.uniqid().'@test.com',
        'phone_e164' => '+919'.rand(100000000, 999999999),
        'password_hash' => bcrypt('password'),
        'password_set_at' => now(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makeInput(int $userId, int $sponsorId, int $placementId, ?string $side = null): PlaceDistributorInput
{
    return new PlaceDistributorInput(
        userId: $userId,
        sponsorId: $sponsorId,
        placementId: $placementId,
        panHash: random_bytes(32),
        panLast4: '1234',
        bankAccountEnc: random_bytes(32),
        bankIfsc: 'SBIN0000000',
        state: 'TS',
        sideOpt: $side,
    );
}

// ─── SLT — Slot resolution ────────────────────────────────────────────────

it('SLT-001: side=L with L empty places at L (referral_explicit)', function () {
    Event::fake();
    $root = seedRootDistributor();
    $u = seedUser();

    $result = makeEngine()->place(makeInput($u, $root, $root, 'L'));

    expect($result->side)->toBe('L')
        ->and($result->sideChosenBy)->toBe('referral_explicit')
        ->and($result->parentId)->toBe($root)
        ->and($result->depth)->toBe(1);
});

it('SLT-002: side=L with L taken throws PlacementSlotFullError', function () {
    $root = seedRootDistributor();
    $engine = makeEngine();
    $engine->place(makeInput(seedUser(), $root, $root, 'L'));   // fills L

    $engine->place(makeInput(seedUser(), $root, $root, 'L'));   // collides
})->throws(PlacementSlotFullError::class);

it('SLT-003: no side, both empty places at L (referral_default)', function () {
    $root = seedRootDistributor();

    $result = makeEngine()->place(makeInput(seedUser(), $root, $root, null));

    expect($result->side)->toBe('L')
        ->and($result->sideChosenBy)->toBe('referral_default');
});

it('SLT-004: no side, L taken falls back to R (referral_fallback_right)', function () {
    $root = seedRootDistributor();
    $engine = makeEngine();
    $engine->place(makeInput(seedUser(), $root, $root, 'L'));

    $result = $engine->place(makeInput(seedUser(), $root, $root, null));

    expect($result->side)->toBe('R')
        ->and($result->sideChosenBy)->toBe('referral_fallback_right');
});

it('SLT-005: no side, both taken throws PlacementSlotsExhaustedError', function () {
    $root = seedRootDistributor();
    $engine = makeEngine();
    $engine->place(makeInput(seedUser(), $root, $root, 'L'));
    $engine->place(makeInput(seedUser(), $root, $root, 'R'));

    $engine->place(makeInput(seedUser(), $root, $root, null));
})->throws(PlacementSlotsExhaustedError::class);

it('SLT-006: side=R with R taken throws PlacementSlotFullError', function () {
    $root = seedRootDistributor();
    $engine = makeEngine();
    $engine->place(makeInput(seedUser(), $root, $root, 'R'));

    $engine->place(makeInput(seedUser(), $root, $root, 'R'));
})->throws(PlacementSlotFullError::class);

// ─── DESC — descendant validation ────────────────────────────────────────

it('DESC-01: placement_id=sponsor_id is allowed', function () {
    $root = seedRootDistributor();

    $result = makeEngine()->place(makeInput(seedUser(), $root, $root, null));

    expect($result->parentId)->toBe($root);
});

it('DESC-02: placement_id outside the sponsor downline throws CrossLinePlacementError', function () {
    $sponsorA = seedRootDistributor(seedUser());
    $sponsorB = seedRootDistributor(seedUser());

    // sponsorA tries to place under sponsorB (who is not in A's downline)
    makeEngine()->place(makeInput(seedUser(), $sponsorA, $sponsorB, null));
})->throws(CrossLinePlacementError::class);

it('DESC-03: placement_id deep in own downline is allowed', function () {
    $root = seedRootDistributor();
    $engine = makeEngine();
    $j1 = $engine->place(makeInput(seedUser(), $root, $root, 'L'))->distributorId;

    // root sponsoring under j1 (j1 is in root's downline)
    $result = $engine->place(makeInput(seedUser(), $root, $j1, 'L'));

    expect($result->parentId)->toBe($j1)
        ->and($result->depth)->toBe(2);
});

// ─── AUD — audit trail ───────────────────────────────────────────────────

it('AUD-01: placement creates an audit log row', function () {
    $root = seedRootDistributor();
    $result = makeEngine()->place(makeInput(seedUser(), $root, $root, 'L'));

    $log = DB::table('audit_log')
        ->where('action', 'genealogy.placement.created')
        ->where('subject_id', $result->distributorId)
        ->first();

    expect($log)->not->toBeNull()
        ->and(json_decode($log->details, true))
        ->toHaveKey('side', 'L')
        ->toHaveKey('side_chosen_by', 'referral_explicit');
});

it('AUD-02: cross-line attempt creates a placement.rejected audit row', function () {
    $a = seedRootDistributor(seedUser());
    $b = seedRootDistributor(seedUser());

    try {
        makeEngine()->place(makeInput(seedUser(), $a, $b, null));
    } catch (CrossLinePlacementError) {
        // expected
    }

    expect(DB::table('audit_log')->where('action', 'genealogy.placement.rejected')->count())
        ->toBeGreaterThan(0);
});

// ─── EVT — events ────────────────────────────────────────────────────────

it('EVT-01: placement dispatches PlacementCreated and DistributorRegistered', function () {
    Event::fake();
    $root = seedRootDistributor();

    makeEngine()->place(makeInput(seedUser(), $root, $root, 'L'));

    Event::assertDispatched(PlacementCreated::class);
    Event::assertDispatched(DistributorRegistered::class);
});

// ─── CLS — closure-table writes ──────────────────────────────────────────

it('CLS-01: closure rows include self + every ancestor of parent', function () {
    $root = seedRootDistributor();
    $engine = makeEngine();

    $j1 = $engine->place(makeInput(seedUser(), $root, $root, 'L'))->distributorId;
    $j2 = $engine->place(makeInput(seedUser(), $root, $j1, 'L'))->distributorId;

    $rows = DB::table('genealogy_closure')->where('descendant_id', $j2)->get();

    // expect: self (depth=0), j1 (depth=1), root (depth=2)
    expect($rows->pluck('depth')->sort()->values()->all())->toBe([0, 1, 2]);
});

// ─── PROP — invariants ──────────────────────────────────────────────────

it('PROP-01: every placement increases depth by exactly 1 from placement_id', function () {
    $root = seedRootDistributor();
    $engine = makeEngine();

    $j1 = $engine->place(makeInput(seedUser(), $root, $root, 'L'));
    $j2 = $engine->place(makeInput(seedUser(), $root, $j1->distributorId, 'L'));
    $j3 = $engine->place(makeInput(seedUser(), $root, $j2->distributorId, 'R'));

    expect($j1->depth)->toBe(1)
        ->and($j2->depth)->toBe(2)
        ->and($j3->depth)->toBe(3);
});

it('PROP-02: no two distributors share (placement_parent_id, placement_side)', function () {
    $root = seedRootDistributor();
    $engine = makeEngine();

    $engine->place(makeInput(seedUser(), $root, $root, 'L'));
    $engine->place(makeInput(seedUser(), $root, $root, 'R'));

    $duplicates = DB::table('distributors')
        ->select('placement_parent_id', 'placement_side', DB::raw('COUNT(*) AS n'))
        ->whereNotNull('placement_side')
        ->groupBy('placement_parent_id', 'placement_side')
        ->having('n', '>', 1)
        ->get();

    expect($duplicates)->toBeEmpty();
});

// ─── HOS — hasOpenSlot helper used by the wizard ────────────────────────

it('HOS-01: hasOpenSlot returns true when both empty', function () {
    $root = seedRootDistributor();

    expect(makeEngine()->hasOpenSlot($root, null))->toBeTrue();
});

it('HOS-02: hasOpenSlot(side=L) reflects only the L slot', function () {
    $root = seedRootDistributor();
    $engine = makeEngine();

    $engine->place(makeInput(seedUser(), $root, $root, 'L'));

    expect($engine->hasOpenSlot($root, 'L'))->toBeFalse()
        ->and($engine->hasOpenSlot($root, 'R'))->toBeTrue()
        ->and($engine->hasOpenSlot($root, null))->toBeTrue();
});

it('HOS-03: hasOpenSlot returns false when both taken', function () {
    $root = seedRootDistributor();
    $engine = makeEngine();
    $engine->place(makeInput(seedUser(), $root, $root, 'L'));
    $engine->place(makeInput(seedUser(), $root, $root, 'R'));

    expect($engine->hasOpenSlot($root, null))->toBeFalse();
});
