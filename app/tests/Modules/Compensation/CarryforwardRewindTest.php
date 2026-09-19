<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\Recompute\CarryforwardRewind;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/** A cut-off row carrying the before-state a rewind reads. */
function cutoffRowWithBeforeState(
    int $distributorId,
    string $date,
    int $power,
    ?string $side,
    int $slab1,
    string $status = GsbCutoffResult::STATUS_CREDITED,
): void {
    GsbCutoffResult::create([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 0, 'score' => 0, 'score_value_paise' => 0,
        'gross_gsb_paise' => 0, 'repurchase_deduction_paise' => 0, 'admin_charge_paise' => 0,
        'tds_paise' => 0, 'net_gsb_paise' => 0,
        'power_cf_before_paise' => $power,
        'power_side_before' => $side,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => $slab1,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => $status,
    ]);
}

function storedCarryforward(int $distributorId): ?GsbCarryforward
{
    return GsbCarryforward::query()->where('distributor_id', $distributorId)->first();
}

it('rewinds to the earliest in-window row for each distributor', function (): void {
    $distributor = Distributor::factory()->create();

    cutoffRowWithBeforeState($distributor->id, '2026-09-10', 111_00, 'L', 22_00);
    cutoffRowWithBeforeState($distributor->id, '2026-09-11', 999_00, 'R', 88_00);

    DB::table('gsb_carryforward')->insert([
        'distributor_id' => $distributor->id,
        'power_side_bv_paise' => 999_99,
        'power_side' => 'R',
        'slab1_weaker_bv_paise' => 99_99,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rewind = app(CarryforwardRewind::class);
    $state = $rewind->readFrom(Carbon::parse('2026-09-10'));
    $rewind->apply($state, static fn (string $_m): null => null);

    $row = storedCarryforward($distributor->id);

    expect((int) $row?->power_side_bv_paise)->toBe(111_00);
    expect((string) $row?->power_side)->toBe('L');
    expect((int) $row?->slab1_weaker_bv_paise)->toBe(22_00);
});

it('ignores rows that never moved the carry-forward', function (): void {
    $distributor = Distributor::factory()->create();

    // Both statuses return before the engine reads the store, so their zeroed
    // before-state is not a state to rewind to — it would invent an all-zero
    // carry-forward for a distributor who never had one.
    cutoffRowWithBeforeState($distributor->id, '2026-09-10', 0, null, 0, GsbCutoffResult::STATUS_BELOW_600BV);
    cutoffRowWithBeforeState($distributor->id, '2026-09-11', 0, null, 0, GsbCutoffResult::STATUS_REPURCHASE_FORFEITED);

    expect(app(CarryforwardRewind::class)->readFrom(Carbon::parse('2026-09-10')))->toBe([]);
});

it('keeps the stored side when a legacy row recorded none', function (): void {
    // power_side_before was added on 2026-07-04 without a backfill, and
    // GsbCutoffService itself falls back to the side already in the store.
    $distributor = Distributor::factory()->create();

    cutoffRowWithBeforeState($distributor->id, '2026-09-10', 500_00, null, 0);

    DB::table('gsb_carryforward')->insert([
        'distributor_id' => $distributor->id,
        'power_side_bv_paise' => 700_00,
        'power_side' => 'L',
        'slab1_weaker_bv_paise' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $logged = [];
    $rewind = app(CarryforwardRewind::class);
    $rewind->apply(
        $rewind->readFrom(Carbon::parse('2026-09-10')),
        function (string $message) use (&$logged): void {
            $logged[] = $message;
        },
    );

    $row = storedCarryforward($distributor->id);

    expect((int) $row?->power_side_bv_paise)->toBe(500_00);
    expect((string) $row?->power_side)->toBe('L');
    expect(implode("\n", $logged))->toContain('had no power_side_before');
});

it('refuses to orphan a carry forward it cannot give a side', function (): void {
    // No stored side either: writing the raw NULL would leave a non-zero balance
    // that the next cut-off adds to NEITHER leg — the carry forward would vanish
    // from the match with nothing reporting it.
    $distributor = Distributor::factory()->create();

    cutoffRowWithBeforeState($distributor->id, '2026-09-10', 500_00, null, 0);

    $rewind = app(CarryforwardRewind::class);

    expect(fn () => $rewind->apply(
        $rewind->readFrom(Carbon::parse('2026-09-10')),
        static fn (string $_m): null => null,
    ))->toThrow(RuntimeException::class, 'would be orphaned');
});
