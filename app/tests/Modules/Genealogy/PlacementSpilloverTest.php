<?php

declare(strict_types=1);

use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\Exceptions\PlacementSlotFullError;
use App\Modules\Genealogy\Services\PlacementEngine;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * ADR-0007 — admin-toggled binary spillover. Self-contained helpers (sp*) so
 * this file doesn't depend on PlacementEngineTest's globals.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Event::fake();
});

function spEngine(): PlacementEngine
{
    return new PlacementEngine(app(DatabaseManager::class), app(Dispatcher::class));
}

function spEnableSpillover(bool $on = true): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => 'placement.spillover.enabled'],
        ['value' => $on ? 'true' : 'false', 'version' => 1, 'updated_at' => now()],
    );
}

function spUser(): int
{
    return DB::table('users')->insertGetId([
        'email' => 'sp'.uniqid().'@test.com',
        'phone_e164' => '+919'.rand(100000000, 999999999),
        'password_hash' => bcrypt('password'),
        'password_set_at' => now(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function spSeedRoot(): int
{
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => spUser(),
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
            'bank_account_enc' => random_bytes(32), 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'placement_side' => null,
            'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }
    DB::table('genealogy_closure')->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);

    return $id;
}

function spInput(int $sponsorId, int $placementId, ?string $side = null): PlaceDistributorInput
{
    return new PlaceDistributorInput(
        userId: spUser(), sponsorId: $sponsorId, placementId: $placementId,
        panHash: random_bytes(32), panLast4: '1234',
        bankAccountEnc: random_bytes(32), bankIfsc: 'SBIN0000000',
        state: 'TS', sideOpt: $side,
    );
}

it('SPILL-01: directed — a full L target spills into the left subtree (spillover_left)', function (): void {
    spEnableSpillover();
    $root = spSeedRoot();
    $engine = spEngine();
    $j1 = $engine->place(spInput($root, $root, 'L'))->distributorId;  // fills root.L

    $r = $engine->place(spInput($root, $root, 'L'));                   // would be full off-spillover

    expect($r->parentId)->toBe($j1)                                   // spilled under the L child
        ->and($r->side)->toBe('L')
        ->and($r->depth)->toBe(2)
        ->and($r->sideChosenBy)->toBe('spillover_left');
    expect((int) DB::table('distributors')->where('id', $r->distributorId)->value('placement_id_at_registration'))
        ->toBe($root);                                               // intended target preserved
});

it('SPILL-02: balanced — no side, both target slots full, takes shallowest open (spillover_balanced)', function (): void {
    spEnableSpillover();
    $root = spSeedRoot();
    $engine = spEngine();
    $j1 = $engine->place(spInput($root, $root, 'L'))->distributorId;
    $engine->place(spInput($root, $root, 'R'));

    $r = $engine->place(spInput($root, $root, null));

    expect($r->parentId)->toBe($j1)                                  // j1 dequeued before j2
        ->and($r->side)->toBe('L')
        ->and($r->depth)->toBe(2)
        ->and($r->sideChosenBy)->toBe('spillover_balanced');
});

it('SPILL-03: spillover on but the immediate slot is open → lands at target, no spillover tag', function (): void {
    spEnableSpillover();
    $root = spSeedRoot();

    $r = spEngine()->place(spInput($root, $root, 'L'));

    expect($r->parentId)->toBe($root)
        ->and($r->side)->toBe('L')
        ->and($r->sideChosenBy)->toBe('referral_explicit');         // not spillover_*
});

it('SPILL-04: spillover OFF (default) — a full side still throws (ADR-0003 regression)', function (): void {
    $root = spSeedRoot();                                           // setting absent → off
    $engine = spEngine();
    $engine->place(spInput($root, $root, 'L'));

    $engine->place(spInput($root, $root, 'L'));
})->throws(PlacementSlotFullError::class);

it('SPILL-05: a spilled node gets correct closure rows up to the root', function (): void {
    spEnableSpillover();
    $root = spSeedRoot();
    $engine = spEngine();
    $j1 = $engine->place(spInput($root, $root, 'L'))->distributorId;

    $spilled = $engine->place(spInput($root, $root, 'L'))->distributorId;  // under j1

    $rows = DB::table('genealogy_closure')->where('descendant_id', $spilled)->get();
    expect($rows->pluck('depth')->sort()->values()->all())->toBe([0, 1, 2]); // self, j1, root
});

it('SPILL-06: repeated directed spillover fills breadth-first with no (parent, side) collisions', function (): void {
    spEnableSpillover();
    $root = spSeedRoot();
    $engine = spEngine();
    $j1 = $engine->place(spInput($root, $root, 'L'))->distributorId; // root.L

    $a = $engine->place(spInput($root, $root, 'L')); // j1.L
    $b = $engine->place(spInput($root, $root, 'L')); // j1.R
    $c = $engine->place(spInput($root, $root, 'L')); // j1.L's child .L (next depth)

    expect([$a->parentId, $a->side])->toBe([$j1, 'L'])
        ->and([$b->parentId, $b->side])->toBe([$j1, 'R'])
        ->and([$c->parentId, $c->side])->toBe([$a->distributorId, 'L'])
        ->and($c->depth)->toBe(3);

    $dups = DB::table('distributors')
        ->select('placement_parent_id', 'placement_side', DB::raw('COUNT(*) AS n'))
        ->whereNotNull('placement_side')
        ->groupBy('placement_parent_id', 'placement_side')
        ->having('n', '>', 1)
        ->get();
    expect($dups)->toBeEmpty();
});
