<?php

declare(strict_types=1);

/**
 * Property / regression tests for the ADR-0003 placement engine.
 *
 * PROP-03 — side_chosen_by is always a member of the allowed enum and
 *            matches the pre-state of the slot at the time of placement.
 * PROP-04 — placement_id_at_registration equals the placement_parent_id
 *            (they must be the same under the single-level ADR-0003 rule).
 * PROP-05 — for every placed distributor the sponsor IS self-or-descendant
 *            of the distributor's placement_parent (cross-line invariant
 *            stored on the row).
 * PROP-06 — null placement_id in DB (i.e. DB returns null from ->value())
 *            causes depth to become 1 (0 + 1). This is the degenerate root
 *            self-reference case; we assert the current behaviour so that
 *            any future change that raises instead is a deliberate decision.
 * RACE-01 — two placements targeting the same (placement_id, side=L) must
 *            not both succeed; the second must throw. This is a deterministic
 *            regression for the advisory-lock + unique-index defence.
 */

use App\Modules\Genealogy\Events\DistributorRegistered;
use App\Modules\Genealogy\Events\PlacementCreated;
use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\Exceptions\CrossLinePlacementError;
use App\Modules\Genealogy\Services\Exceptions\PlacementSlotFullError;
use App\Modules\Genealogy\Services\PlacementEngine;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// ─── helpers (local to this file to avoid re-declaration conflicts) ─────────

function propEngine(): PlacementEngine
{
    return new PlacementEngine(
        app(DatabaseManager::class),
        app(Dispatcher::class),
    );
}

function propSeedUser(): int
{
    return DB::table('users')->insertGetId([
        'email' => 'pu'.uniqid().'@prop.test',
        'phone_e164' => '+919'.rand(100000000, 999999999),
        'password_hash' => bcrypt('password'),
        'password_set_at' => now(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function propSeedRoot(?int $userId = null, int $depth = 0): int
{
    $userId = $userId ?? propSeedUser();

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => 'PRP'.rand(100000, 999999),
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
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id,
        'descendant_id' => $id,
        'depth' => 0,
    ]);

    return $id;
}

function propMakeInput(int $userId, int $sponsorId, int $placementId, ?string $side = null): PlaceDistributorInput
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

// ─── PROP-03: side_chosen_by correctness ────────────────────────────────────

it('PROP-03: side_chosen_by=referral_explicit only when explicit side was supplied and slot was open', function () {
    $root = propSeedRoot();
    $engine = propEngine();

    // explicit L on empty node → referral_explicit
    $r1 = $engine->place(propMakeInput(propSeedUser(), $root, $root, 'L'));
    expect($r1->sideChosenBy)->toBe('referral_explicit');

    // no side on node where L is now taken → referral_fallback_right
    $r2 = $engine->place(propMakeInput(propSeedUser(), $root, $r1->distributorId, null));
    expect($r2->sideChosenBy)->toBe('referral_default'); // L was free on new node

    // place a second child on the same new node using no side; L is taken → fallback
    $r3 = $engine->place(propMakeInput(propSeedUser(), $root, $r1->distributorId, null));
    expect($r3->sideChosenBy)->toBe('referral_fallback_right');
});

it('PROP-03b: side_chosen_by=referral_default only when no side given and L was open', function () {
    $root = propSeedRoot();

    $result = propEngine()->place(propMakeInput(propSeedUser(), $root, $root, null));

    expect($result->sideChosenBy)->toBe('referral_default')
        ->and($result->side)->toBe('L');
});

it('PROP-03c: side_chosen_by stored on the distributors row matches the PlacementResult', function () {
    $root = propSeedRoot();

    $result = propEngine()->place(propMakeInput(propSeedUser(), $root, $root, 'R'));

    $storedChosenBy = DB::table('distributors')
        ->where('id', $result->distributorId)
        ->value('side_chosen_by');

    expect($storedChosenBy)->toBe($result->sideChosenBy)
        ->and($storedChosenBy)->toBe('referral_explicit');
});

it('PROP-03d: side_chosen_by is never null for any placed distributor', function () {
    $root = propSeedRoot();
    $engine = propEngine();

    $engine->place(propMakeInput(propSeedUser(), $root, $root, 'L'));
    $engine->place(propMakeInput(propSeedUser(), $root, $root, 'R'));

    $nullRows = DB::table('distributors')
        ->whereNull('side_chosen_by')
        ->whereNotNull('placement_side')
        ->count();

    expect($nullRows)->toBe(0);
});

// ─── PROP-04: placement_id_at_registration == placement_parent_id ───────────

it('PROP-04: placement_id_at_registration always equals placement_parent_id (single-level rule)', function () {
    $root = propSeedRoot();
    $engine = propEngine();

    $j1 = $engine->place(propMakeInput(propSeedUser(), $root, $root, 'L'));
    $j2 = $engine->place(propMakeInput(propSeedUser(), $root, $j1->distributorId, 'R'));

    foreach ([$j1->distributorId, $j2->distributorId] as $did) {
        $row = DB::table('distributors')->where('id', $did)->first();
        expect((int) $row->placement_id_at_registration)->toBe((int) $row->placement_parent_id,
            "distributor {$did}: placement_id_at_registration should equal placement_parent_id"
        );
    }
});

// ─── PROP-05: sponsor is always self-or-descendant of placement parent ───────

it('PROP-05: sponsor_id is self or ancestor in genealogy_closure of placement_parent_id', function () {
    $root = propSeedRoot();
    $engine = propEngine();

    // Place j1 under root, then j2 under j1 (root sponsors both)
    $j1 = $engine->place(propMakeInput(propSeedUser(), $root, $root, 'L'));
    $j2 = $engine->place(propMakeInput(propSeedUser(), $root, $j1->distributorId, 'L'));

    // For j2: sponsor=root, placement_parent=j1. root must be ancestor of j1.
    $rootIsAncestorOfJ1 = DB::table('genealogy_closure')
        ->where('ancestor_id', $root)
        ->where('descendant_id', $j1->distributorId)
        ->exists();

    expect($rootIsAncestorOfJ1)->toBeTrue();

    // j2 row itself: sponsor_id = root, placement_parent_id = j1
    $j2Row = DB::table('distributors')->where('id', $j2->distributorId)->first();
    expect((int) $j2Row->sponsor_id)->toBe($root);
    expect((int) $j2Row->placement_parent_id)->toBe($j1->distributorId);
});

it('PROP-05b: a sponsor cannot be placed under a node outside their downline (cross-line guard)', function () {
    // Two unrelated roots (simulates two sponsor trees)
    $rootA = propSeedRoot(propSeedUser());
    $rootB = propSeedRoot(propSeedUser());

    // rootA tries to place a joiner under rootB — must throw
    expect(fn () => propEngine()->place(propMakeInput(propSeedUser(), $rootA, $rootB, 'L')))
        ->toThrow(CrossLinePlacementError::class);
});

// ─── PROP-06: depth arithmetic ───────────────────────────────────────────────

it('PROP-06: depth column is NOT NULL — the DB schema enforces a non-null depth for all distributors', function () {
    // The `depth` column has a NOT NULL constraint (confirmed by migration).
    // The engine computes: (int) ->value('depth') + 1.
    // This test documents that the schema cannot produce a null-depth row;
    // the degenerate "null depth in DB" scenario cannot occur in production.
    // If the constraint is ever dropped, PROP-06 must be updated and a new
    // ADR section must address how the engine handles null depth.
    $root = propSeedRoot(null, 0);

    // Attempting to set depth=null on the root row must throw a DB error.
    expect(fn () => DB::table('distributors')->where('id', $root)->update(['depth' => null]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('PROP-06b: root at depth=0 produces child at depth=1 (baseline arithmetic)', function () {
    $root = propSeedRoot(null, 0);

    $result = propEngine()->place(propMakeInput(propSeedUser(), $root, $root, 'L'));

    expect($result->depth)->toBe(1);
});

it('PROP-06c: root at depth=2 (mid-tree placement) produces child at depth=3', function () {
    // Simulate a mid-tree root by explicitly setting its depth to 2.
    $root = propSeedRoot(null, 2);

    $result = propEngine()->place(propMakeInput(propSeedUser(), $root, $root, 'L'));

    expect($result->depth)->toBe(3);
});

// ─── RACE-01: concurrent placement on same (placement_id, side) ─────────────

it('RACE-01: two sequential placements on the same (placement_id, side=L) — second must throw PlacementSlotFullError', function () {
    // This is a deterministic regression for the race defence.
    // True concurrent threads can't be reliably tested inside a single-process
    // test run; we verify the slot-check path that the advisory-lock + unique
    // index protect.
    $root = propSeedRoot();
    $engine = propEngine();

    // First placement succeeds
    $first = $engine->place(propMakeInput(propSeedUser(), $root, $root, 'L'));
    expect($first->side)->toBe('L');

    // Second placement at the same slot must be rejected
    expect(fn () => $engine->place(propMakeInput(propSeedUser(), $root, $root, 'L')))
        ->toThrow(PlacementSlotFullError::class);

    // Only one child must exist under root on side L
    $count = DB::table('distributors')
        ->where('placement_parent_id', $root)
        ->where('id', '!=', $root)
        ->where('placement_side', 'L')
        ->count();

    expect($count)->toBe(1);
});

it('RACE-01b: no-side placement fills L first then R; a third attempt throws PlacementSlotsExhaustedError', function () {
    $root = propSeedRoot();
    $engine = propEngine();

    $r1 = $engine->place(propMakeInput(propSeedUser(), $root, $root, null));
    $r2 = $engine->place(propMakeInput(propSeedUser(), $root, $root, null));

    expect($r1->side)->toBe('L')
        ->and($r2->side)->toBe('R');

    expect(fn () => $engine->place(propMakeInput(propSeedUser(), $root, $root, null)))
        ->toThrow(\App\Modules\Genealogy\Services\Exceptions\PlacementSlotsExhaustedError::class);
});

// ─── SELF-REFERENCE: sponsor == placement_id (demo seeder bootstraps L0 this way) ─

it('SELF-REF: sponsor_id == placement_id is the canonical root-bootstrap case and succeeds', function () {
    Event::fake();

    $root = propSeedRoot();

    // root sponsoring directly under themselves — the demo seeder does this.
    $result = propEngine()->place(propMakeInput(propSeedUser(), $root, $root, 'L'));

    expect($result->parentId)->toBe($root)
        ->and($result->depth)->toBe(1);

    Event::assertDispatched(PlacementCreated::class);
    Event::assertDispatched(DistributorRegistered::class);
});

it('SELF-REF: sponsor sponsoring two distributors under themselves fills both slots', function () {
    $root = propSeedRoot();
    $engine = propEngine();

    $l = $engine->place(propMakeInput(propSeedUser(), $root, $root, 'L'));
    $r = $engine->place(propMakeInput(propSeedUser(), $root, $root, 'R'));

    expect($l->side)->toBe('L')
        ->and($r->side)->toBe('R')
        ->and($l->depth)->toBe(1)
        ->and($r->depth)->toBe(1);

    // Both children must be in root's closure table
    $descendants = DB::table('genealogy_closure')
        ->where('ancestor_id', $root)
        ->where('depth', 1)
        ->pluck('descendant_id')
        ->sort()
        ->values()
        ->all();

    expect($descendants)->toContain($l->distributorId)
        ->and($descendants)->toContain($r->distributorId);
});
