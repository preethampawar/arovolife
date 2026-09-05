<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly engines now record a distributor excluded by the repurchase
 * wallet = ₹0 gate as 'repurchase_wallet_blocked' — an audit row that says the
 * month was earned but not paid, distinct from the repurchase-cycle statuses
 * (which are about a missed repurchase, not an unspent wallet).
 *
 * fortune_bonus_results is still a real enum on SQLite (a CHECK constraint), so
 * the test connection needs the column rebuilt as well; gbb_monthly_results and
 * rank_bonus_results were already widened to plain strings there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gbb_monthly_results MODIFY COLUMN status ENUM('pending','credited','reversed','repurchase_held','repurchase_suspended','repurchase_wallet_blocked') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE rank_bonus_results MODIFY COLUMN status ENUM('pending','credited','reversed','requalification_held','repurchase_wallet_blocked') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE fortune_bonus_results MODIFY COLUMN status ENUM('pending','credited','skipped','repurchase_wallet_blocked') NOT NULL DEFAULT 'pending'");

            return;
        }

        Schema::table('fortune_bonus_results', function (Blueprint $table): void {
            $table->string('status', 32)->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gbb_monthly_results MODIFY COLUMN status ENUM('pending','credited','reversed','repurchase_held','repurchase_suspended') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE rank_bonus_results MODIFY COLUMN status ENUM('pending','credited','reversed','requalification_held') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE fortune_bonus_results MODIFY COLUMN status ENUM('pending','credited','skipped') NOT NULL DEFAULT 'pending'");

            return;
        }

        Schema::table('fortune_bonus_results', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'credited', 'skipped'])->default('pending')->change();
        });
    }
};
