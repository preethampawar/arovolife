<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Growth Booster run now persists 'repurchase_held' (grace window —
 * calculated at the frozen point value, releasable) and 'repurchase_suspended'
 * (post-grace — audit-only, forfeited) rows, but the status column only knows
 * pending|credited|reversed.
 *
 * Unlike gsb_cutoff_results — whose SQLite column is plain TEXT, so its widening
 * migration is MySQL-only — this table's enum is enforced on SQLite by a CHECK
 * constraint, so the test connection needs the rebuild too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gbb_monthly_results MODIFY COLUMN status ENUM('pending','credited','reversed','repurchase_held','repurchase_suspended') NOT NULL DEFAULT 'pending'");

            return;
        }

        Schema::table('gbb_monthly_results', function (Blueprint $table): void {
            $table->string('status', 32)->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gbb_monthly_results MODIFY COLUMN status ENUM('pending','credited','reversed') NOT NULL DEFAULT 'pending'");

            return;
        }

        Schema::table('gbb_monthly_results', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'credited', 'reversed'])->default('pending')->change();
        });
    }
};
