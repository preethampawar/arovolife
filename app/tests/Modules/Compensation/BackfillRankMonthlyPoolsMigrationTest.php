<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    DB::table('rank_monthly_pools')->delete();
    DB::table('rank_bonus_results')->delete();
});

/**
 * The forward-only backfill that gives a month the pre-freeze engine already
 * paid the pool row the frozen engine requires.
 */
function runBackfillRankMonthlyPoolsMigration(): void
{
    $migration = require app_path('Modules/Compensation/Database/Migrations/2026_09_11_100000_backfill_rank_monthly_pools.php');

    $migration->up();
}

/** A result row as the pre-freeze engine wrote it: economics on every row. */
function legacyRankResult(
    int $distributorId,
    string $month,
    int $rank,
    int $gross,
    string $status = RankBonusResult::STATUS_CREDITED,
    int $pool = 2_504_320,
    int $qualifiers = 2,
    ?int $aogoPoints = null,
): int {
    return DB::table('rank_bonus_results')->insertGetId([
        'distributor_id' => $distributorId,
        'month_start' => $month,
        'rank_number' => $rank,
        'company_turnover_paise' => 178_880_000,
        'pool_paise' => $pool,
        'qualifier_count' => $qualifiers,
        'rap_points' => $rank === 1 ? 10 : null,
        'aogo_points' => $aogoPoints,
        'total_points' => $rank === 1 ? 25 : null,
        'point_value_paise' => $rank === 1 ? 100_100 : null,
        'gross_paise' => $gross,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_paise' => $gross,
        'status' => $status,
        'credited_at' => $status === RankBonusResult::STATUS_CREDITED ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('reconstructs the pool of a month that was paid before the pool table existed', function (): void {
    $a = Distributor::factory()->create();
    $b = Distributor::factory()->create();

    legacyRankResult($a->id, '2026-09-01', 1, 1_001_000, aogoPoints: null);
    legacyRankResult($b->id, '2026-09-01', 1, 500_500, aogoPoints: 5);

    runBackfillRankMonthlyPoolsMigration();

    $pool = DB::table('rank_monthly_pools')->where('rank_number', 1)->sole();

    expect($pool->month_start)->toStartWith('2026-09-01')
        ->and((int) $pool->company_turnover_paise)->toBe(178_880_000)
        ->and((int) $pool->pool_paise)->toBe(2_504_320)
        ->and((int) $pool->payable_count)->toBe(2)
        ->and((int) $pool->aogo_points)->toBe(5)
        ->and((int) $pool->total_points)->toBe(25)
        ->and((int) $pool->point_value_paise)->toBe(100_100)
        // Gross actually written, and what the pool did not spend.
        ->and((int) $pool->payout_paise)->toBe(1_501_500)
        ->and((int) $pool->leftover_paise)->toBe(1_002_820)
        // Never snapshotted by the pre-freeze engine: 0 says so honestly.
        ->and((int) $pool->envelope_bp)->toBe(0)
        ->and((float) $pool->pool_pct)->toBe(0.0);
});

it('writes one row per rank the month paid', function (): void {
    $a = Distributor::factory()->create();
    $b = Distributor::factory()->create();

    legacyRankResult($a->id, '2026-09-01', 1, 1_001_000);
    legacyRankResult($b->id, '2026-09-01', 2, 1_216_384, pool: 1_216_384, qualifiers: 1);

    runBackfillRankMonthlyPoolsMigration();

    expect(DB::table('rank_monthly_pools')->orderBy('rank_number')->pluck('rank_number')->all())
        ->toBe([1, 2]);
});

it('leaves a month that already has a frozen pool untouched, and is idempotent', function (): void {
    $dist = Distributor::factory()->create();
    legacyRankResult($dist->id, '2026-09-01', 1, 1_001_000);

    DB::table('rank_monthly_pools')->insert([
        'month_start' => '2026-09-01',
        'rank_number' => 1,
        'company_turnover_paise' => 999,
        'envelope_bp' => 2_000,
        'pool_pct' => 7,
        'pool_paise' => 999,
        'rap_points' => null,
        'payable_count' => 1,
        'aogo_points' => 0,
        'total_points' => null,
        'point_value_paise' => null,
        'gross_per_qualifier_paise' => 999,
        'payout_paise' => 999,
        'leftover_paise' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runBackfillRankMonthlyPoolsMigration();
    runBackfillRankMonthlyPoolsMigration();

    $pool = DB::table('rank_monthly_pools')->sole();

    expect((int) $pool->company_turnover_paise)->toBe(999)
        ->and((int) $pool->envelope_bp)->toBe(2_000);
});

it('leaves a month whose rows were never credited open to a real freeze', function (): void {
    $dist = Distributor::factory()->create();
    legacyRankResult($dist->id, '2026-09-01', 1, 1_001_000, status: RankBonusResult::STATUS_PENDING);

    runBackfillRankMonthlyPoolsMigration();

    expect(DB::table('rank_monthly_pools')->count())->toBe(0);
});

it('lets the Rank Bonus run for a backfilled month instead of throwing', function (): void {
    // The point of the whole migration: `refuseUnfrozenPaidMonth()` used to
    // abort `compensation:monthly-close --restart` at step 2 and take steps
    // 3-7 with it.
    $credited = Distributor::factory()->create();
    $pending = Distributor::factory()->create();

    legacyRankResult($credited->id, '2026-08-01', 1, 1_001_000);
    legacyRankResult($pending->id, '2026-08-01', 1, 500_500, status: RankBonusResult::STATUS_PENDING);

    runBackfillRankMonthlyPoolsMigration();

    $service = app(RankBonusService::class);

    expect(fn (): array => $service->runForMonth(Carbon::parse('2026-08-01')))
        ->not->toThrow(RuntimeException::class);

    // The already-paid row is untouched; the pending one is credited at the
    // gross the frozen month recorded for it, never re-priced.
    expect(RankBonusResult::where('distributor_id', $credited->id)->sole()->gross_paise)->toBe(1_001_000)
        ->and(RankBonusResult::where('distributor_id', $pending->id)->sole()->gross_paise)->toBe(500_500);
});
