<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (test env) stores enums as strings — MODIFY COLUMN is MySQL-only.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gsb_cutoff_results MODIFY COLUMN status ENUM('no_match','calculated','credited','failed','frozen','below_600bv','reversed') NOT NULL DEFAULT 'no_match'");
        }
        // On SQLite the column is an unconstrained string; 'reversed' is already valid with no DDL change.
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE gsb_cutoff_results MODIFY COLUMN status ENUM('no_match','calculated','credited','failed','frozen','below_600bv') NOT NULL DEFAULT 'no_match'");
        }
    }
};
