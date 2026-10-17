<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire the 'repurchase_failed_blocked' status from gbb_monthly_results. It
 * was added by 2026_10_09_100400 for the month-end repurchase verdict gate
 * (assumption A-G1). The client answered 2026-10-09 (Q5 G1) that a distributor
 * with a ₹0 repurchase wallet at month end is paid GBB even when their
 * repurchase window lapsed and was not renewed, so the gate was removed and no
 * engine writes the status any more. The wallet gate
 * ('repurchase_wallet_blocked') is unchanged.
 *
 * Fail-safe: count first and refuse before any DDL while a single row still
 * carries the status — narrowing a MySQL ENUM under such a row would rewrite
 * or reject it, and the month that row belongs to was frozen without that
 * distributor's AGP. Such a month has to be replayed on the new rule (dev and
 * staging recompute) or wiped (production before launch), never patched.
 *
 * MySQL only. SQLite needs no DDL: gbb_monthly_results.status is a plain
 * string there (2026_08_05_110003). The refusal runs on every driver.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.narrow_gbb_repurchase_failed_blocked_status';

    private const REMOVED = 'repurchase_failed_blocked';

    public function up(): void
    {
        $carrying = DB::table('gbb_monthly_results')->where('status', self::REMOVED)->count();

        if ($carrying > 0) {
            throw new RuntimeException("Refusing to narrow the growth booster status enum: {$carrying} gbb_monthly_results rows still carry repurchase_failed_blocked. Replay or wipe history first.");
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gbb_monthly_results MODIFY COLUMN status ENUM('pending','credited','reversed','repurchase_wallet_blocked') NOT NULL DEFAULT 'pending'");
        }

        // Through the model, not a raw insert: the creating hook links the
        // row into the audit hash chain, which a raw insert would skip.
        AuditLog::create([
            'actor_id' => null,
            'action' => self::ACTION,
            'subject_type' => 'compensation_status_enum',
            'subject_id' => null,
            'details' => [
                'migration' => '2026_10_10_100000_narrow_gbb_repurchase_failed_blocked_status',
                'reason' => 'Client 2026-10-09 (Q5 G1): the repurchase cycle verdict never withholds GBB; only the month-end wallet gate does.',
                'driver' => DB::getDriverName(),
                'gbb_monthly_results' => ['column' => 'status', 'removed' => [self::REMOVED], 'rows_carrying' => $carrying],
            ],
        ]);
    }

    public function down(): void
    {
        // Schema only: the enum widens back to the list 2026_10_09_101200
        // wrote. No row moves. Audited like the narrowing, so a rollback is
        // as visible as the run.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gbb_monthly_results MODIFY COLUMN status ENUM('pending','credited','reversed','repurchase_wallet_blocked','repurchase_failed_blocked') NOT NULL DEFAULT 'pending'");
        }

        AuditLog::create([
            'actor_id' => null,
            'action' => self::ACTION.'.rolled_back',
            'subject_type' => 'compensation_status_enum',
            'subject_id' => null,
            'details' => [
                'migration' => '2026_10_10_100000_narrow_gbb_repurchase_failed_blocked_status',
                'driver' => DB::getDriverName(),
                'gbb_monthly_results' => ['column' => 'status', 'restored' => [self::REMOVED]],
            ],
        ]);
    }
};
