<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Client 2026-10-09: a sponsor below the royalty rank who is failed on
        // their repurchase condition on the cut-off day earns no Mentorship
        // points that day, and the verdict is frozen on a 'repurchase_gated' row
        // (fail-safe finding F-3). Widen the MySQL ENUM or strict-mode MySQL
        // throws on the insert and the record is lost. SQLite stores the column
        // as a string — no DDL needed. Schema only: no row moves.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE mentorship_bonus_results MODIFY COLUMN status ENUM('credited','failed','repurchase_gated') NOT NULL DEFAULT 'credited'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Never guess: narrowing the enum under a gated row would rewrite or
        // reject it. Refuse while any exists.
        $gated = (int) DB::table('mentorship_bonus_results')->where('status', 'repurchase_gated')->count();

        if ($gated > 0) {
            throw new RuntimeException("Cannot narrow mentorship_bonus_results.status: {$gated} row(s) carry 'repurchase_gated'. Remove or re-derive them deliberately first.");
        }

        DB::statement("ALTER TABLE mentorship_bonus_results MODIFY COLUMN status ENUM('credited','failed') NOT NULL DEFAULT 'credited'");
    }
};
