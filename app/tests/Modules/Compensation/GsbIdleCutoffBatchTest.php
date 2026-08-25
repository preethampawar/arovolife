<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\GsbIdleCutoffBatch;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.gsb.topup_golive_date'],
        ['value' => today()->addYears(10)->toDateString()],
    );
});

function idleBatchDistributor(int $personalBvPaise): Distributor
{
    $dist = Distributor::factory()->create();

    if ($personalBvPaise > 0) {
        BvLedgerEntry::create([
            'distributor_id' => $dist->id,
            'order_id' => random_int(5_000_000, 5_999_999),
            'bv_paise' => $personalBvPaise,
            'type' => 'accrual',
            'effective_at' => now(),
        ]);
    }

    return $dist;
}

/**
 * Every column that identifies the row's content, ignoring id and timestamps.
 *
 * @return array<string, mixed>
 */
function idleBatchRowShape(int $distributorId, string $date): array
{
    $row = (array) DB::table('gsb_cutoff_results')
        ->where('distributor_id', $distributorId)
        ->whereDate('cutoff_date', $date)
        ->first();

    unset($row['id'], $row['created_at'], $row['updated_at']);

    return $row;
}

it('writes bulk rows identical to the ones the engine would settle', function (): void {
    $date = Carbon::today();
    $dateStr = $date->toDateString();

    $belowMin = idleBatchDistributor(50_000);   // 500 BV — under the 600 BV gate
    $idle = idleBatchDistributor(100_000);      // eligible, but no business at all
    $active = idleBatchDistributor(100_000);    // eligible, with group BV today

    GroupBvDaily::create([
        'distributor_id' => $active->id,
        'date' => $dateStr,
        'left_bv_paise' => 500_000,
        'right_bv_paise' => 300_000,
    ]);

    Artisan::call('gsb:daily-cutoff', ['--date' => $dateStr]);

    $viaBulk = [
        'below_min' => idleBatchRowShape($belowMin->id, $dateStr),
        'idle' => idleBatchRowShape($idle->id, $dateStr),
    ];
    $activeRow = idleBatchRowShape($active->id, $dateStr);

    // The bulk path must have produced a carry-forward row for the eligible
    // idle distributor and none for the below-minimum one.
    expect(GsbCarryforward::where('distributor_id', $idle->id)->exists())->toBeTrue()
        ->and(GsbCarryforward::where('distributor_id', $belowMin->id)->exists())->toBeFalse();

    // Re-run the same two distributors through the real engine and compare.
    GsbCutoffResult::whereIn('distributor_id', [$belowMin->id, $idle->id])->delete();
    GsbCarryforward::whereIn('distributor_id', [$belowMin->id, $idle->id])->delete();

    $engine = app(GsbCutoffService::class);
    $engine->runForDistributor($belowMin->id, $date);
    $engine->runForDistributor($idle->id, $date);

    expect(idleBatchRowShape($belowMin->id, $dateStr))->toBe($viaBulk['below_min'])
        ->and(idleBatchRowShape($idle->id, $dateStr))->toBe($viaBulk['idle']);

    // ...and the engine leaves the same carry-forward footprint.
    expect(GsbCarryforward::where('distributor_id', $idle->id)->value('power_side'))->toBe('L')
        ->and(GsbCarryforward::where('distributor_id', $belowMin->id)->exists())->toBeFalse();

    // The distributor with real business still went through the engine.
    expect($activeRow['left_bv_paise'])->toBe(500_000)
        ->and($activeRow['right_bv_paise'])->toBe(300_000)
        ->and($activeRow['status'])->toBe(GsbCutoffResult::STATUS_NO_MATCH);
});

it('still shortcuts an idle distributor on the days after their first', function (): void {
    $date = Carbon::today();
    $idle = idleBatchDistributor(100_000);

    // Day one leaves behind the zero carry-forward row settle() always creates.
    Artisan::call('gsb:daily-cutoff', ['--date' => $date->copy()->subDay()->toDateString()]);
    expect(GsbCarryforward::where('distributor_id', $idle->id)->value('power_side'))->toBe('L');

    // That row carries nothing, so day two is still a shortcut — otherwise the
    // fast path would only ever fire once per replay.
    $partition = app(GsbIdleCutoffBatch::class)
        ->partition(Distributor::query()->get(['id', 'gsb_frozen_at']), $date);

    expect(array_keys($partition['idle']))->toContain($idle->id);

    // ...and the row it writes is the one the engine would have settled, side
    // fields included — power_side_before carries yesterday's recorded side.
    Artisan::call('gsb:daily-cutoff', ['--date' => $date->toDateString()]);
    $viaBulk = idleBatchRowShape($idle->id, $date->toDateString());

    GsbCutoffResult::where('distributor_id', $idle->id)->whereDate('cutoff_date', $date)->delete();
    GsbCarryforward::where('distributor_id', $idle->id)->update([
        'power_side_bv_paise' => 0,
        'power_side' => 'L',
        'slab1_weaker_bv_paise' => 0,
    ]);

    app(GsbCutoffService::class)->runForDistributor($idle->id, $date);

    expect(idleBatchRowShape($idle->id, $date->toDateString()))->toBe($viaBulk)
        ->and($viaBulk['power_side_before'])->toBe('L')
        ->and($viaBulk['power_side_after'])->toBe('L')
        ->and($viaBulk['status'])->toBe(GsbCutoffResult::STATUS_NO_MATCH);
});

it('never shortcuts a distributor with carry-forward, BV or an existing row', function (): void {
    $date = Carbon::today();
    $dateStr = $date->toDateString();

    $withCf = idleBatchDistributor(100_000);
    GsbCarryforward::create([
        'distributor_id' => $withCf->id,
        'power_side_bv_paise' => 700_000,
        'power_side' => 'R',
        'slab1_weaker_bv_paise' => 0,
    ]);

    $partition = app(GsbIdleCutoffBatch::class)
        ->partition(Distributor::query()->get(['id', 'gsb_frozen_at']), $date);

    expect(array_keys($partition['idle']))->not->toContain($withCf->id)
        ->and($partition['below_min'])->not->toContain($withCf->id);

    // Its real cut-off keeps the carry-forward on the Right side.
    Artisan::call('gsb:daily-cutoff', ['--date' => $dateStr]);

    $row = idleBatchRowShape($withCf->id, $dateStr);
    expect($row['power_cf_before_paise'])->toBe(700_000)
        ->and($row['power_side_before'])->toBe('R')
        ->and($row['power_cf_after_paise'])->toBe(700_000);
});

it('takes no shortcut when the smallest payable slab needs zero matched BV', function (): void {
    DB::table('gsb_slabs')->where('slab', 1)->update(['matched_bv_paise' => 0]);

    $idle = idleBatchDistributor(100_000);

    $partition = app(GsbIdleCutoffBatch::class)
        ->partition(Distributor::query()->get(['id', 'gsb_frozen_at']), Carbon::today());

    expect($partition['idle'])->toBe([])
        ->and($partition['below_min'])->toBe([])
        ->and($partition['engine']->count())->toBe(1);
});

it('still shortcuts a distributor whose only activity is pending personal BV', function (): void {
    // The top-up is live, so pendingBvPaise() would return a real figure. The
    // fast path is still correct because the engine only ATTEMPTS a top-up once
    // a leg has reached the smallest slab, and an idle distributor's legs are
    // both zero. This pins that reasoning, which is otherwise implicit in the
    // guard at the top of partition().
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.gsb.topup_golive_date'],
        ['value' => today()->subYear()->toDateString()],
    );

    $date = Carbon::today();
    $dateStr = $date->toDateString();

    $pending = idleBatchDistributor(200_000);   // 2,000 BV of personal purchases, no group BV

    $partition = app(GsbIdleCutoffBatch::class)
        ->partition(Distributor::query()->get(['id', 'gsb_frozen_at']), $date);

    expect(array_keys($partition['idle']))->toContain($pending->id);

    app(GsbIdleCutoffBatch::class)->write($partition['below_min'], $partition['idle'], $date);
    $bulk = idleBatchRowShape($pending->id, $dateStr);

    GsbCutoffResult::query()->delete();
    GsbCarryforward::query()->delete();
    $engine = app(GsbCutoffService::class);
    $engine->warmBatch(collect([$pending]));
    $engine->settle($engine->computeForDistributor($pending->id, $date));

    expect(idleBatchRowShape($pending->id, $dateStr))->toBe($bulk);

    // And the pending BV is still pending — the shortcut consumed nothing.
    expect(DB::table('gsb_personal_bv_topups')->count())->toBe(0);
});
