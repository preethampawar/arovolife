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

/** A cut-off result row for one distributor on one day. */
function gbaCutoffRow(int $distributorId, Carbon $date, string $status): void
{
    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'gross_gsb_paise' => 0, 'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('lands BV on the paid day while that day has not been cut off', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');

    app(GroupBvAccumulatorService::class)->propagate($leftChild->id, 200_000, Carbon::yesterday(), 9301);

    expect(GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', Carbon::yesterday()->toDateString())->value('left_bv_paise'))->toBe(200_000);
    expect(GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', Carbon::today()->toDateString())->exists())->toBeFalse();
});

it('moves BV that reaches the worker after the paid day was cut off onto today, never a day nothing reads again', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');
    gbaCutoffRow($root->id, Carbon::yesterday(), 'no_match');

    app(GroupBvAccumulatorService::class)->propagate($leftChild->id, 200_000, Carbon::yesterday(), 9302);

    expect(GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', Carbon::yesterday()->toDateString())->exists())->toBeFalse();
    expect(GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', Carbon::today()->toDateString())->value('left_bv_paise'))->toBe(200_000);

    // The snapshot names the day the BV actually landed, so a reversal debits the right row.
    expect(DB::table('group_bv_credits')->where('order_id', 9302)->where('ancestor_id', $root->id)->value('date'))
        ->toStartWith(Carbon::today()->toDateString());
});

it('moves late BV onto today for an ancestor with no row once the paid day has been cut off for anyone', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');
    $stranger = Distributor::factory()->create(['depth' => 0]);
    gbaCutoffRow($stranger->id, Carbon::yesterday(), 'no_match');

    app(GroupBvAccumulatorService::class)->propagate($leftChild->id, 200_000, Carbon::yesterday(), 9303);

    expect(GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', Carbon::today()->toDateString())->value('left_bv_paise'))->toBe(200_000);
});

it('moves late BV onto today while the paid day\'s cut-off is running', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');
    DB::table('engine_runs')->insert([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::yesterday()->toDateString(),
        'status' => 'running',
        'trigger' => 'scheduler',
        'started_at' => now()->subMinute(),
    ]);

    app(GroupBvAccumulatorService::class)->propagate($leftChild->id, 200_000, Carbon::yesterday(), 9304);

    expect(GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', Carbon::today()->toDateString())->value('left_bv_paise'))->toBe(200_000);
});

it('keeps late BV on a forfeited or below-600 day, which discards it as if it had arrived on time', function (string $status) {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');
    gbaCutoffRow($root->id, Carbon::yesterday(), $status);
    gbaCutoffRow($leftChild->id, Carbon::yesterday(), 'no_match');

    app(GroupBvAccumulatorService::class)->propagate($leftChild->id, 200_000, Carbon::yesterday(), 9305);

    expect(GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', Carbon::yesterday()->toDateString())->value('left_bv_paise'))->toBe(200_000);
    expect(GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', Carbon::today()->toDateString())->exists())->toBeFalse();
})->with(['repurchase_forfeited', 'below_600bv']);

it('keeps late BV on the paid day for an ancestor whose run failed there — the retry reads it', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');
    gbaCutoffRow($root->id, Carbon::yesterday(), 'failed');
    gbaCutoffRow($leftChild->id, Carbon::yesterday(), 'no_match');

    app(GroupBvAccumulatorService::class)->propagate($leftChild->id, 200_000, Carbon::yesterday(), 9306);

    expect(GroupBvDaily::where('distributor_id', $root->id)->whereDate('date', Carbon::yesterday()->toDateString())->value('left_bv_paise'))->toBe(200_000);
});

it('dates the propagation marker by the day the BV landed, so a windowed wipe takes it with the credits', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');
    gbaCutoffRow($root->id, Carbon::yesterday(), 'no_match');
    DB::table('bv_propagation_log')->insert([
        'order_id' => 9307, 'distributor_id' => $leftChild->id, 'bv_paise' => 200_000, 'date' => Carbon::yesterday()->toDateString(),
    ]);

    app(GroupBvAccumulatorService::class)->propagate($leftChild->id, 200_000, Carbon::yesterday(), 9307);

    expect(DB::table('bv_propagation_log')->where('order_id', 9307)->value('date'))->toStartWith(Carbon::today()->toDateString());
});
