<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Client spec 2026-09-07 §2.1: a failed repurchase day is forfeited, and
        // the cut-off records it as 'repurchase_forfeited'. Widen the MySQL ENUM
        // or strict-mode MySQL throws on the insert and the day's result row is
        // lost. SQLite (test env) stores the column as a string — no DDL needed.
        //
        // The legacy 'repurchase_held' / 'repurchase_suspended' members stay:
        // rows written before this change are still valid data.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gsb_cutoff_results MODIFY COLUMN status ENUM('no_match','calculated','credited','failed','frozen','below_600bv','reversed','repurchase_held','repurchase_suspended','repurchase_forfeited') NOT NULL DEFAULT 'no_match'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gsb_cutoff_results MODIFY COLUMN status ENUM('no_match','calculated','credited','failed','frozen','below_600bv','reversed','repurchase_held','repurchase_suspended') NOT NULL DEFAULT 'no_match'");
        }
    }
};
