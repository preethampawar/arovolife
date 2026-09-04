<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Services\FortuneBonusService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DEV-ONLY staging E2E verification for Fortune Bonus.
 *
 * Seeds a chain-tree (10 positions, one at each level 0-9) using existing
 * distributor records, injects one synthetic BV entry (FK-checks off) so the
 * pool-freeze step computes the reference pool, then runs the full engine path
 * and compares gross_paise in fortune_bonus_results against the oracle values.
 *
 * Usage:
 *   php artisan fortune:staging-e2e-seed [--month=2025-12-01]
 *   php artisan fortune:staging-e2e-seed --month=2025-12-01 --rollback
 *
 * DELETE THIS COMMAND before production launch — it disables FK checks
 * temporarily and writes synthetic data to wallet_ledger_entries.
 */
final class FortuneStagingE2ESeedCommand extends Command
{
    protected $signature = 'fortune:staging-e2e-seed
        {--month=2025-12-01 : Test month start (YYYY-MM-DD). Must have no real FB data.}
        {--rollback : Remove all data seeded by a prior run for this month.}';

    protected $description = '[DEV] Chain-tree Fortune Bonus staging E2E seed + engine run + oracle comparison.';

    /**
     * Chain-tree positions: each position's parent (via intdiv(pos+1,3)) is the
     * previous entry — giving exactly one distributor at each matrix level 0-9.
     */
    private const array CHAIN_POSITIONS = [1, 2, 5, 14, 41, 122, 365, 1094, 3281, 9842];

    /**
     * Oracle expected gross incomes (paise) per matrix level, for the reference
     * 5.32-crore-BV pool. From FortuneBonusVerificationTest chain-tree section.
     */
    private const array ORACLE_GROSS_PAISE = [
        0 => 3_000_000,  // L0: ₹30,000  (capped)
        1 => 3_000_000,  // L1: ₹30,000  (capped)
        2 => 3_000_000,  // L2: ₹30,000  (capped)
        3 => 3_000_000,  // L3: ₹30,000  (capped)
        4 => 2_000_000,  // L4: ₹20,000  (capped)
        5 => 1_000_000,  // L5: ₹10,000  (capped)
        6 => 500_000,  // L6: ₹5,000   (capped)
        7 => 250_000,  // L7: ₹2,500   (capped)
        8 => 150_000,  // L8: ₹1,500   (capped)
        9 => 3_000,  // L9: ₹30      (minimum only)
    ];

    /**
     * 5.32 crore BV × 100 paise/BV = 5,320,000,000 bv_paise.
     * Pool = 5% of this = 266,000,000 paise = ₹26,60,000.
     */
    private const int COMPANY_BV_PAISE = 5_320_000_000;

    /** Synthetic order_id used for the fake BV entry — must not exist in orders. */
    private const int FAKE_ORDER_ID = 999_999_998;

    public function handle(FortuneBonusService $fb): int
    {
        $monthStart = Carbon::parse((string) $this->option('month'))->startOfMonth();
        $monthStartDate = $monthStart->toDateString();

        return $this->option('rollback')
            ? $this->rollback($monthStartDate)
            : $this->seed($fb, $monthStart, $monthStartDate);
    }

    private function seed(FortuneBonusService $fb, Carbon $monthStart, string $monthStartDate): int
    {
        $this->warn('=== Fortune Bonus Staging E2E Seed ===');
        $this->warn('[DEV-ONLY] Writes synthetic data. Ensure a DB backup exists before running.');
        $this->line("Month: {$monthStartDate}");
        $this->newLine();

        // Guard: abort if a real pool or participants already exist for this month.
        if (DB::table('fortune_monthly_pools')->where('month_start', $monthStartDate)->exists()) {
            $this->error("fortune_monthly_pools already has a row for {$monthStartDate}. Choose a different month or run --rollback.");

            return self::FAILURE;
        }

        if (DB::table('fortune_bonus_participants')->where('month_start', $monthStartDate)->exists()) {
            $this->error("fortune_bonus_participants already has rows for {$monthStartDate}. Run --rollback first.");

            return self::FAILURE;
        }

        // Verify fortune_bonus_levels are seeded (migrations 2026-09-03/04 must have run).
        $levelCount = DB::table('fortune_bonus_levels')->where('is_active', true)->count();
        if ($levelCount < 10) {
            $this->error("fortune_bonus_levels has only {$levelCount} active rows (expected 10). Run: php artisan migrate");

            return self::FAILURE;
        }

        // --- Step 1: pick 10 existing active distributors -----------------------
        $distributorIds = DB::table('distributors')
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(10)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if (count($distributorIds) < 10) {
            $this->error('Need at least 10 active distributors. Found: '.count($distributorIds));

            return self::FAILURE;
        }

        $this->info('Distributors: '.implode(', ', $distributorIds));

        // --- Step 2: insert fortune_bonus_participants at chain positions --------
        $now = now()->toDateTimeString();
        $participants = [];

        foreach (self::CHAIN_POSITIONS as $idx => $position) {
            $level = FortuneBonusParticipant::levelFromPosition($position);
            $participants[] = [
                'distributor_id' => $distributorIds[$idx],
                'month_start' => $monthStartDate,
                'position' => $position,
                'matrix_level' => $level,
                'eligibility_tier' => 'non_ranked',
                'first_gsb_date' => $monthStartDate,
                'enrolled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('fortune_bonus_participants')->insert($participants);
        $this->info('Inserted 10 fortune_bonus_participants at positions: '.implode(', ', self::CHAIN_POSITIONS));

        // --- Step 3: inject synthetic BV (FK checks off for fake order_id) ------
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('bv_ledger_entries')->insert([
            'distributor_id' => $distributorIds[0],
            'order_id' => self::FAKE_ORDER_ID,
            'bv_paise' => self::COMPANY_BV_PAISE,
            'type' => 'accrual',
            'effective_at' => $monthStart->copy()->addDays(14)->format('Y-m-d H:i:s.u'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->info('Inserted synthetic BV entry: '.self::COMPANY_BV_PAISE.' paise (₹'.number_format(self::COMPANY_BV_PAISE / 100, 0).')');

        // --- Step 4: run the engine ----------------------------------------------
        $this->newLine();
        $this->info('Running FortuneBonusService::runForMonth()...');
        $engineResult = $fb->runForMonth($monthStart);

        $this->line(sprintf(
            'Pool: ₹%s | Points: %s | Credited: %d | Leftover: ₹%s',
            number_format((int) $engineResult['pool_paise'] / 100, 0),
            number_format((int) $engineResult['total_points'], 0),
            $engineResult['credited'],
            number_format((int) $engineResult['leftover_paise'] / 100, 0),
        ));

        // --- Step 5: compare fortune_bonus_results against oracle ---------------
        $this->newLine();
        $this->info('=== Oracle Comparison ===');

        $resultsByDist = DB::table('fortune_bonus_results')
            ->where('month_start', $monthStartDate)
            ->whereIn('distributor_id', $distributorIds)
            ->get()
            ->keyBy('distributor_id');

        $rows = [];
        $allPass = true;

        foreach (self::CHAIN_POSITIONS as $idx => $position) {
            $level = $idx;
            $distId = $distributorIds[$idx];
            $expected = self::ORACLE_GROSS_PAISE[$level];
            $result = $resultsByDist[$distId] ?? null;
            $actual = $result ? (int) $result->gross_paise : null;
            $points = $result ? (int) $result->points : '?';
            $match = $actual === $expected;

            if (! $match) {
                $allPass = false;
            }

            $rows[] = [
                "L{$level}",
                number_format($position, 0, '.', ','),
                $distId,
                $points,
                '₹'.number_format($expected / 100, 0),
                $actual !== null ? '₹'.number_format($actual / 100, 0) : 'MISSING',
                $match ? 'MATCH' : 'MISMATCH',
            ];
        }

        $this->table(
            ['Level', 'Position', 'Dist ID', 'Points', 'Oracle', 'App', 'Status'],
            $rows,
        );

        // Pool summary line.
        $poolPaise = (int) $engineResult['pool_paise'];
        $expectedPool = (int) (self::COMPANY_BV_PAISE * 500 / 10_000); // 5%
        $poolMatch = $poolPaise === $expectedPool;
        if (! $poolMatch) {
            $allPass = false;
        }

        $this->line(sprintf(
            'Pool: expected ₹%s, actual ₹%s — %s',
            number_format($expectedPool / 100, 0),
            number_format($poolPaise / 100, 0),
            $poolMatch ? 'MATCH' : 'MISMATCH',
        ));

        $this->newLine();

        if ($allPass) {
            $this->info('ALL LEVELS MATCH — Fortune Bonus staging E2E PASSED.');
        } else {
            $this->error('MISMATCH DETECTED — Review table above.');
        }

        $this->newLine();
        $this->line("Rollback: php artisan fortune:staging-e2e-seed --month={$monthStartDate} --rollback");

        return $allPass ? self::SUCCESS : self::FAILURE;
    }

    private function rollback(string $monthStartDate): int
    {
        $this->warn("Rolling back Fortune Bonus staging E2E data for {$monthStartDate}...");

        // Collect result IDs to remove their wallet credits.
        $resultIds = DB::table('fortune_bonus_results')
            ->where('month_start', $monthStartDate)
            ->pluck('id')
            ->toArray();

        if ($resultIds) {
            $deleted = DB::table('wallet_ledger_entries')
                ->where('reference_type', 'fortune_bonus_result')
                ->whereIn('reference_id', $resultIds)
                ->delete();
            $this->line("Deleted {$deleted} wallet_ledger_entries.");
        }

        $r = DB::table('fortune_bonus_results')->where('month_start', $monthStartDate)->delete();
        $this->line("Deleted {$r} fortune_bonus_results.");

        $poolId = DB::table('fortune_monthly_pools')
            ->where('month_start', $monthStartDate)
            ->value('id');

        if ($poolId) {
            DB::table('fortune_monthly_pool_levels')->where('fortune_monthly_pool_id', $poolId)->delete();
            DB::table('fortune_monthly_pools')->where('id', $poolId)->delete();
            $this->line('Deleted fortune_monthly_pools + levels.');
        }

        $p = DB::table('fortune_bonus_participants')->where('month_start', $monthStartDate)->delete();
        $this->line("Deleted {$p} fortune_bonus_participants.");

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $bv = DB::table('bv_ledger_entries')->where('order_id', self::FAKE_ORDER_ID)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->line("Deleted {$bv} synthetic bv_ledger_entries.");

        $this->info('Rollback complete.');

        return self::SUCCESS;
    }
}
