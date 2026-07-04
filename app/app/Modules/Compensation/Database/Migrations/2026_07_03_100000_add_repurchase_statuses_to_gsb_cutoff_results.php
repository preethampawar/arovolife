<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The repurchase engine (2026-06-29) writes 'repurchase_held' and
        // 'repurchase_suspended' cut-off statuses, but the MySQL ENUM was never
        // widened — on strict-mode MySQL the insert throws and the day's result
        // row is lost. SQLite (test env) stores the column as a string, so no
        // DDL is needed there.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gsb_cutoff_results MODIFY COLUMN status ENUM('no_match','calculated','credited','failed','frozen','below_600bv','reversed','repurchase_held','repurchase_suspended') NOT NULL DEFAULT 'no_match'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gsb_cutoff_results MODIFY COLUMN status ENUM('no_match','calculated','credited','failed','frozen','below_600bv','reversed') NOT NULL DEFAULT 'no_match'");
        }
    }
};
