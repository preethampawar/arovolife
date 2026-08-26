<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\DTOs\RecomputeReport;
use App\Modules\Compensation\Services\Recompute\CompensationRecomputeRunner;
use App\Modules\Compensation\Services\Recompute\WindowedStateWiper;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    config(['arovolife.recompute.enabled' => true]);
});

/** A settled cut-off row for one distributor on one date. */
function windowedCutoffRow(int $distributorId, string $date, int $cfBefore, int $cfAfter, ?string $side = 'L'): void
{
    GsbCutoffResult::create([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 0,
        'right_bv_paise' => 0,
        'weaker_bv_paise' => 0,
        'gross_gsb_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 0,
        'power_cf_before_paise' => $cfBefore,
        'power_side_before' => $side,
        'power_cf_after_paise' => $cfAfter,
        'power_side_after' => $side,
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_NO_MATCH,
    ]);
}

it('keeps rows before the window and removes the ones on or after it', function (): void {
    $dist = Distributor::factory()->create();

    windowedCutoffRow($dist->id, '2026-08-10', 100_000, 200_000);
    windowedCutoffRow($dist->id, '2026-08-14', 200_000, 300_000);
    windowedCutoffRow($dist->id, '2026-08-15', 300_000, 400_000);
    windowedCutoffRow($dist->id, '2026-08-20', 400_000, 500_000);

    app(WindowedStateWiper::class)->wipe(Carbon::parse('2026-08-15'));

    $remaining = GsbCutoffResult::orderBy('cutoff_date')->pluck('cutoff_date')
        ->map(fn ($d): string => Carbon::parse($d)->toDateString())->all();

    expect($remaining)->toBe(['2026-08-10', '2026-08-14']);
});

it('rewinds carry-forward to the before-state of the first in-window cut-off', function (): void {
    $dist = Distributor::factory()->create();

    windowedCutoffRow($dist->id, '2026-08-14', 200_000, 300_000, 'R');
    windowedCutoffRow($dist->id, '2026-08-15', 300_000, 400_000, 'L');
    windowedCutoffRow($dist->id, '2026-08-20', 400_000, 900_000, 'L');

    // The rolling store holds the LAST run's outcome...
    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 900_000,
        'power_side' => 'L',
        'slab1_weaker_bv_paise' => 55_000,
    ]);

    app(WindowedStateWiper::class)->wipe(Carbon::parse('2026-08-15'));

    // ...and must be rewound to what the 15th started from, not left at the 20th's.
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->power_side_bv_paise)->toBe(300_000)
        ->and($cf->power_side)->toBe('L')
        ->and($cf->slab1_weaker_bv_paise)->toBe(0);
});

it('leaves the carry-forward alone for a distributor with no rows in the window', function (): void {
    $dist = Distributor::factory()->create();

    windowedCutoffRow($dist->id, '2026-08-01', 10_000, 20_000);
    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 20_000,
        'power_side' => 'L',
        'slab1_weaker_bv_paise' => 5_000,
    ]);

    app(WindowedStateWiper::class)->wipe(Carbon::parse('2026-08-15'));

    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->power_side_bv_paise)->toBe(20_000)
        ->and($cf->slab1_weaker_bv_paise)->toBe(5_000);
});

it('rewinds reversal debt by what the deleted credits and reversals moved', function (): void {
    $dist = Distributor::factory()->create();

    DB::table('group_bv_debts')->insert([
        'distributor_id' => $dist->id,
        'side' => 'L',
        'bv_paise' => 10_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // In-window: a credit paid 25,000 of debt down, a reversal created 5,000.
    DB::table('group_bv_credits')->insert([
        'order_id' => 1,
        'ancestor_id' => $dist->id,
        'side' => 'L',
        'bv_paise' => 0,
        'debt_consumed_paise' => 25_000,
        'date' => '2026-08-20',
        'created_at' => now(),
    ]);
    DB::table('group_bv_reversals')->insert([
        'order_id' => 1,
        'ancestor_id' => $dist->id,
        'side' => 'L',
        'bv_paise' => 5_000,
        'absorbed_paise' => 0,
        'debt_paise' => 5_000,
        'date' => '2026-08-21',
        'created_at' => now(),
    ]);

    app(WindowedStateWiper::class)->wipe(Carbon::parse('2026-08-15'));

    // 10,000 now + 25,000 consumed back − 5,000 created = 30,000 before the window.
    expect((int) DB::table('group_bv_debts')->where('distributor_id', $dist->id)->value('bv_paise'))
        ->toBe(30_000);
});

it('keeps a wallet credit whose source row survives the window', function (): void {
    // Monthly engines run in arrears: July's Growth Booster is credited to the
    // wallet on 2 August. A window opening on 1 August deletes the credit by
    // created_at but keeps July's result — and the replay skips July as already
    // computed, so without this rule the money silently disappears. (Found on
    // the reference dataset: 45 entries, ₹20.1 lakh.)
    $dist = Distributor::factory()->create();

    $julyResultId = DB::table('gbb_monthly_results')->insertGetId([
        'distributor_id' => $dist->id,
        'year_month' => '2026-07-01',
        'agp_earned' => 1,
        'company_turnover_paise' => 0,
        'pool_paise' => 0,
        'total_pool_agp' => 1,
        'point_value_paise' => 10_860_500,
        'gbb_gross_paise' => 10_860_500,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'gbb_net_paise' => 10_860_500,
        'status' => 'credited',
        'created_at' => '2026-08-02 08:00:00',
        'updated_at' => '2026-08-02 08:00:00',
    ]);

    DB::table('wallet_ledger_entries')->insert([
        [
            'distributor_id' => $dist->id,
            'type' => 'gbb_credit',
            'amount_paise' => 10_860_500,
            'reference_id' => $julyResultId,
            'reference_type' => 'gbb_monthly_result',
            'created_at' => '2026-08-02 08:00:00',
        ],
        [
            // ...while an in-window GSB credit whose cut-off row IS being
            // rebuilt must go, or the replay would double it.
            'distributor_id' => $dist->id,
            'type' => 'gsb_credit',
            'amount_paise' => 200_000,
            'reference_id' => 999_999,
            'reference_type' => 'gsb_cutoff_result',
            'created_at' => '2026-08-20 00:10:00',
        ],
    ]);

    app(WindowedStateWiper::class)->wipe(Carbon::parse('2026-08-15'));

    $remaining = DB::table('wallet_ledger_entries')->pluck('type')->all();
    expect($remaining)->toBe(['gbb_credit'])
        ->and(DB::table('gbb_monthly_results')->count())->toBe(1);
});

it('never invents a carry-forward row for a distributor who has never purchased', function (): void {
    // A below_600bv row records zeros because the engine returns before it
    // reads the carry-forward store. Rewinding from one would create an
    // all-zero row a full replay never creates (126 of 288 on the reference
    // dataset), leaving windowed and full recomputes disagreeing.
    $dist = Distributor::factory()->create();

    GsbCutoffResult::create([
        'distributor_id' => $dist->id,
        'cutoff_date' => '2026-08-20',
        'left_bv_paise' => 0,
        'right_bv_paise' => 0,
        'weaker_bv_paise' => 0,
        'gross_gsb_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_BELOW_600BV,
    ]);

    app(WindowedStateWiper::class)->wipe(Carbon::parse('2026-08-15'));

    expect(GsbCarryforward::where('distributor_id', $dist->id)->exists())->toBeFalse();
});

it('deletes monthly results from the first of their month, not mid-month', function (): void {
    $dist = Distributor::factory()->create();

    $row = fn (string $month): array => [
        'distributor_id' => $dist->id,
        'month_start' => $month,
        'rank_number' => 1,
        'company_turnover_paise' => 0,
        'pool_paise' => 0,
        'qualifier_count' => 0,
        'rap_points' => 0,
        'aogo_points' => 0,
        'total_points' => 0,
        'point_value_paise' => 0,
        'gross_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_paise' => 0,
        'status' => 'credited',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('rank_bonus_results')->insert([$row('2026-07-01'), $row('2026-08-01')]);

    app(WindowedStateWiper::class)->wipe(Carbon::parse('2026-08-15'));

    expect(DB::table('rank_bonus_results')->pluck('month_start')->map(fn ($m): string => Carbon::parse($m)->toDateString())->all())
        ->toBe(['2026-07-01']);
});

it('widens a window that opens inside a closed month to that month', function (): void {
    $report = app(CompensationRecomputeRunner::class)->run(
        from: Carbon::today()->subMonthNoOverflow()->setDay(15),
        to: Carbon::today(),
        windowed: true,
    );

    expect($report->mode)->toBe(RecomputeReport::MODE_WINDOWED)
        ->and($report->from->day)->toBe(1)
        ->and($report->warnings)->toContain(sprintf(
            'Window widened to %s: %s falls in a closed month, whose monthly bonuses can only be rebuilt whole.',
            Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            Carbon::today()->subMonthNoOverflow()->setDay(15)->toDateString(),
        ));
});

it('replays a window that opens in the current month from the requested day', function (): void {
    $from = Carbon::today()->startOfMonth()->isSameDay(Carbon::today())
        ? Carbon::today()
        : Carbon::today()->subDay();

    $report = app(CompensationRecomputeRunner::class)->run(from: $from, windowed: true);

    expect($report->from->toDateString())->toBe($from->toDateString())
        ->and($report->warnings)->toBe([]);
});

it('replays only the engines asked for and names the ones it skipped', function (): void {
    $report = app(CompensationRecomputeRunner::class)->run(
        from: Carbon::today(),
        onlyEngineKeys: ['gsb.daily-cutoff'],
    );

    expect(array_keys($report->enginesRun))->toBe(['gsb:daily-cutoff']);

    $skippedWarning = collect($report->warnings)->first(fn (string $w): bool => str_starts_with($w, 'Engines not replayed:'));
    expect($skippedWarning)->not->toBeNull()
        ->and($skippedWarning)->toContain('gsb.weekly-payout')
        ->and($skippedWarning)->toContain('repurchase.evaluate');
});

it('keeps the stored power side when a legacy row has no power_side_before', function (): void {
    // power_side_before was added on 2026-07-04 without a backfill, so older
    // rows carry NULL. Writing that NULL through would leave a non-zero carry
    // forward with no side, and the next cut-off adds a null-sided balance to
    // neither leg — the balance silently leaves the match.
    $distributor = Distributor::factory()->create();
    $date = Carbon::today()->toDateString();

    GsbCarryforward::create([
        'distributor_id' => $distributor->id,
        'power_side_bv_paise' => 900_000,
        'power_side' => 'R',
        'slab1_weaker_bv_paise' => 0,
    ]);

    windowedCutoffRow($distributor->id, $date, 900_000, 900_000, null);

    app(WindowedStateWiper::class)->wipe(Carbon::today(), static fn (string $m): null => null);

    $cf = GsbCarryforward::query()->where('distributor_id', $distributor->id)->firstOrFail();

    expect($cf->power_side_bv_paise)->toBe(900_000)
        ->and($cf->power_side)->toBe('R');
});

it('refuses to rewind a legacy carry forward that has no side anywhere', function (): void {
    $distributor = Distributor::factory()->create();
    $date = Carbon::today()->toDateString();

    GsbCarryforward::create([
        'distributor_id' => $distributor->id,
        'power_side_bv_paise' => 900_000,
        'power_side' => null,
        'slab1_weaker_bv_paise' => 0,
    ]);

    windowedCutoffRow($distributor->id, $date, 900_000, 900_000, null);

    // Better to stop and demand a full recompute than to orphan the balance.
    expect(fn () => app(WindowedStateWiper::class)->wipe(Carbon::today(), static fn (string $m): null => null))
        ->toThrow(RuntimeException::class, 'Run a full recompute');
});
