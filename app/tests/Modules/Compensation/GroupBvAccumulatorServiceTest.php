<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Services\GroupBvAccumulatorService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * Helper: create a distributor placed as a child of $parent on $side.
 * Inserts the closure table rows manually (like PlacementEngine would).
 */
function makePlacedDistributor(Distributor $parent, string $side): Distributor
{
    $child = Distributor::factory()->create([
        'placement_parent_id' => $parent->id,
        'placement_side' => $side,
        'depth' => $parent->depth + 1,
    ]);
    // Insert closure rows: self-ref + all ancestor rows
    DB::table('genealogy_closure')->insert(['ancestor_id' => $child->id, 'descendant_id' => $child->id, 'depth' => 0]);
    $ancestors = DB::table('genealogy_closure')->where('descendant_id', $parent->id)->get();
    foreach ($ancestors as $row) {
        DB::table('genealogy_closure')->insert([
            'ancestor_id' => $row->ancestor_id,
            'descendant_id' => $child->id,
            'depth' => $row->depth + 1,
        ]);
    }

    return $child;
}

it('accumulates BV into the correct group for a direct child', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');

    $svc = app(GroupBvAccumulatorService::class);
    $date = Carbon::today();
    $svc->propagate($leftChild->id, 500_000, $date);  // 5,000 BV

    $row = GroupBvDaily::where('distributor_id', $root->id)->where('date', $date->toDateString())->first();
    expect($row)->not->toBeNull();
    expect($row->left_bv_paise)->toBe(500_000);
    expect($row->right_bv_paise)->toBe(0);
});

it('accumulates BV for a grandchild through two levels', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');
    $grandchild = makePlacedDistributor($leftChild, 'R');  // right of left child = still LEFT of root

    $svc = app(GroupBvAccumulatorService::class);
    $date = Carbon::today();
    $svc->propagate($grandchild->id, 300_000, $date);

    $rootRow = GroupBvDaily::where('distributor_id', $root->id)->where('date', $date->toDateString())->first();
    $leftRow = GroupBvDaily::where('distributor_id', $leftChild->id)->where('date', $date->toDateString())->first();

    expect($rootRow->left_bv_paise)->toBe(300_000);  // grandchild is in root's left group
    expect($rootRow->right_bv_paise)->toBe(0);
    expect($leftRow->right_bv_paise)->toBe(300_000);  // grandchild is in leftChild's right group
});

it('adds to existing accumulator on the same date', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');

    $svc = app(GroupBvAccumulatorService::class);
    $date = Carbon::today();
    $svc->propagate($leftChild->id, 200_000, $date);
    $svc->propagate($leftChild->id, 300_000, $date);  // second order same day

    $row = GroupBvDaily::where('distributor_id', $root->id)->where('date', $date->toDateString())->first();
    expect($row->left_bv_paise)->toBe(500_000);
});

it('produces no accumulator rows for a tree root with no ancestors', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);

    $svc = app(GroupBvAccumulatorService::class);
    $svc->propagate($root->id, 500_000, Carbon::today());

    expect(GroupBvDaily::where('distributor_id', $root->id)->count())->toBe(0);
});

it('propagation on one date does not affect a different date', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');

    $svc = app(GroupBvAccumulatorService::class);
    $dateA = Carbon::today();
    $dateB = Carbon::today()->subDay();

    $svc->propagate($leftChild->id, 200_000, $dateA);

    $rowA = GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', $dateA->toDateString())->first();
    $rowB = GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', $dateB->toDateString())->first();

    expect($rowA->left_bv_paise)->toBe(200_000);
    expect($rowB)->toBeNull();
});
