<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Client 2026-09-06 rule 7 adds Rank Bonus to the four bonuses a failed
     * repurchase cycle withholds, and rule 8 says withheld income is released
     * on fulfilment rather than forfeited. Both Rank and Fortune therefore need
     * the releasable 'repurchase_held' status that GSB and GBB already have —
     * `repurchase_wallet_blocked` cannot be reused for it, because a hold can
     * now also be caused by a BV shortfall, and because rows already written
     * under that name were forfeitures.
     *
     * SQLite (the test connection) has both columns as plain strings already,
     * so only MySQL needs the enum widened.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE rank_bonus_results MODIFY COLUMN status ENUM('pending','credited','reversed','requalification_held','repurchase_wallet_blocked','repurchase_held') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE fortune_bonus_results MODIFY COLUMN status ENUM('pending','credited','skipped','repurchase_wallet_blocked','repurchase_held') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE rank_bonus_results MODIFY COLUMN status ENUM('pending','credited','reversed','requalification_held','repurchase_wallet_blocked') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE fortune_bonus_results MODIFY COLUMN status ENUM('pending','credited','skipped','repurchase_wallet_blocked') NOT NULL DEFAULT 'pending'");
    }
};
