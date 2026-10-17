<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client 2026-10-09 (A-G1): a Growth Booster earner who is failed on their
 * repurchase condition on the month's last day is frozen on a
 * 'repurchase_failed_blocked' roster row (gross 0, AGP out of the
 * denominator). Widen the MySQL ENUM or strict-mode MySQL throws on the insert
 * and the record is lost.
 *
 * SQLite needs no DDL: 2026_08_05_110003 rebuilt gbb_monthly_results.status as
 * a plain string there, and 2026_09_05_100002 confirms it was left that way.
 * Schema only: no row moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gbb_monthly_results MODIFY COLUMN status ENUM('pending','credited','reversed','repurchase_held','repurchase_suspended','repurchase_wallet_blocked','repurchase_failed_blocked') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Never guess: narrowing the enum under a blocked row would rewrite or
        // reject it. Refuse while any exists.
        $blocked = (int) DB::table('gbb_monthly_results')->where('status', 'repurchase_failed_blocked')->count();

        if ($blocked > 0) {
            throw new RuntimeException("Cannot narrow gbb_monthly_results.status: {$blocked} row(s) carry 'repurchase_failed_blocked'. Remove or re-derive them deliberately first.");
        }

        DB::statement("ALTER TABLE gbb_monthly_results MODIFY COLUMN status ENUM('pending','credited','reversed','repurchase_held','repurchase_suspended','repurchase_wallet_blocked') NOT NULL DEFAULT 'pending'");
    }
};
