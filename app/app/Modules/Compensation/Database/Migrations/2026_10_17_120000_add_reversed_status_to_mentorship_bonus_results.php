<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Client decision M5 (2026-09-27): reversing a GSB credit also reverses
        // the Mentorship Bonus earned on it, and the bonus row is marked
        // 'reversed'. Widen the MySQL ENUM or strict-mode MySQL throws on the
        // update and the reversal rolls back. SQLite stores the column as a
        // string — no DDL needed. Schema only: no row moves.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE mentorship_bonus_results MODIFY COLUMN status ENUM('credited','failed','repurchase_gated','reversed') NOT NULL DEFAULT 'credited'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Never guess: narrowing the enum under a reversed row would rewrite or
        // reject it. Refuse while any exists.
        $reversed = (int) DB::table('mentorship_bonus_results')->where('status', 'reversed')->count();

        if ($reversed > 0) {
            throw new RuntimeException("Cannot narrow mentorship_bonus_results.status: {$reversed} row(s) carry 'reversed'. Remove or re-derive them deliberately first.");
        }

        DB::statement("ALTER TABLE mentorship_bonus_results MODIFY COLUMN status ENUM('credited','failed','repurchase_gated') NOT NULL DEFAULT 'credited'");
    }
};
