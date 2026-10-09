<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire the pre-forfeit 'repurchase_held' / 'repurchase_suspended' statuses
 * from gsb_cutoff_results and gbb_monthly_results. They came from the
 * 2026-09-06 hold-and-release model that the client's 2026-09-07 forfeit spec
 * replaced; no engine writes them, and after the history replay (dev/staging)
 * or the pre-launch wipe (production) no row carries them.
 *
 * Fail-safe: count first and refuse before any DDL while a single row still
 * carries a legacy status — narrowing a MySQL ENUM under such a row would
 * rewrite or reject it.
 *
 * MySQL only, like every widening before it (2026_06_25_091803,
 * 2026_07_03_100000, 2026_09_07_100001 for gsb; 2026_08_05_110003,
 * 2026_10_09_100400 for gbb). SQLite has nothing to narrow: no widening ever
 * ran DDL there and both columns accept any string on the test driver today
 * (LegacyStatusNarrowingMigrationTest inserts a legacy value on SQLite to
 * exercise the refusal), so there is no constraint to shrink. The refusal runs
 * on every driver.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.narrow_legacy_repurchase_held_statuses';

    private const GSB_LEGACY = ['repurchase_held', 'repurchase_suspended'];

    private const GBB_LEGACY = ['repurchase_held', 'repurchase_suspended'];

    public function up(): void
    {
        $gsb = DB::table('gsb_cutoff_results')->whereIn('status', self::GSB_LEGACY)->count();
        $gbb = DB::table('gbb_monthly_results')->whereIn('status', self::GBB_LEGACY)->count();

        if ($gsb > 0 || $gbb > 0) {
            throw new RuntimeException("Refusing to narrow status enums: {$gsb} gsb_cutoff_results and {$gbb} gbb_monthly_results rows still carry a legacy held/suspended status. Replay or wipe history first.");
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gsb_cutoff_results MODIFY COLUMN status ENUM('no_match','calculated','credited','failed','frozen','below_600bv','reversed','repurchase_forfeited') NOT NULL DEFAULT 'no_match'");
            DB::statement("ALTER TABLE gbb_monthly_results MODIFY COLUMN status ENUM('pending','credited','reversed','repurchase_wallet_blocked','repurchase_failed_blocked') NOT NULL DEFAULT 'pending'");
        }

        // Through the model, not a raw insert: the creating hook links the
        // row into the audit hash chain, which a raw insert would skip.
        AuditLog::create([
            'actor_id' => null,
            'action' => self::ACTION,
            'subject_type' => 'compensation_status_enum',
            'subject_id' => null,
            'details' => [
                'migration' => '2026_10_09_101200_narrow_legacy_repurchase_held_statuses',
                'reason' => 'The client\'s 2026-09-07 forfeit spec replaced hold-and-release; no engine writes the held/suspended statuses.',
                'driver' => DB::getDriverName(),
                'gsb_cutoff_results' => ['removed' => self::GSB_LEGACY, 'rows_carrying' => $gsb],
                'gbb_monthly_results' => ['removed' => self::GBB_LEGACY, 'rows_carrying' => $gbb],
            ],
        ]);
    }

    public function down(): void
    {
        // Schema only: the enums widen back to the 2026_09_07_100001 and
        // 2026_10_09_100400 lists. No row moves.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE gsb_cutoff_results MODIFY COLUMN status ENUM('no_match','calculated','credited','failed','frozen','below_600bv','reversed','repurchase_held','repurchase_suspended','repurchase_forfeited') NOT NULL DEFAULT 'no_match'");
        DB::statement("ALTER TABLE gbb_monthly_results MODIFY COLUMN status ENUM('pending','credited','reversed','repurchase_held','repurchase_suspended','repurchase_wallet_blocked','repurchase_failed_blocked') NOT NULL DEFAULT 'pending'");
    }
};
