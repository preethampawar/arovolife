<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    DB::table('rank_monthly_pools')->delete();
    DB::table('rank_bonus_results')->delete();
});

/**
 * The forward-only backfill that gave a month the pre-freeze engine already
 * paid the pool row the frozen engine requires. It reads rank_tiers.pool_pct
 * and writes rank_monthly_pools.pool_pct, both dropped on 2026-10-09 (client
 * 2026-10-05 two-pass pool); on a fresh install it runs before those drops.
 * Re-run against the final schema it must be a no-op, never an error.
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
): int {
    return DB::table('rank_bonus_results')->insertGetId([
        'distributor_id' => $distributorId,
        'month_start' => $month,
        'rank_number' => $rank,
        'company_turnover_paise' => 178_880_000,
        'pool_paise' => 2_504_320,
        'qualifier_count' => 2,
        'rap_points' => $rank === 1 ? 10 : null,
        'aogo_points' => null,
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

it('runs on a schema whose pool_pct columns are gone', function (): void {
    expect(Schema::hasColumn('rank_tiers', 'pool_pct'))->toBeFalse()
        ->and(Schema::hasColumn('rank_monthly_pools', 'pool_pct'))->toBeFalse();
});

it('is a no-op on the current schema even for a paid month without a pool row', function (): void {
    $a = Distributor::factory()->create();
    $b = Distributor::factory()->create();

    legacyRankResult($a->id, '2026-09-01', 1, 1_001_000);
    legacyRankResult($b->id, '2026-09-01', 2, 1_216_384);

    runBackfillRankMonthlyPoolsMigration();
    runBackfillRankMonthlyPoolsMigration();

    expect(DB::table('rank_monthly_pools')->count())->toBe(0)
        ->and(DB::table('rank_bonus_results')->count())->toBe(2);
});

it('leaves a month whose rows were never credited open to a real freeze', function (): void {
    $dist = Distributor::factory()->create();
    legacyRankResult($dist->id, '2026-09-01', 1, 1_001_000, status: RankBonusResult::STATUS_PENDING);

    runBackfillRankMonthlyPoolsMigration();

    expect(DB::table('rank_monthly_pools')->count())->toBe(0);
});
