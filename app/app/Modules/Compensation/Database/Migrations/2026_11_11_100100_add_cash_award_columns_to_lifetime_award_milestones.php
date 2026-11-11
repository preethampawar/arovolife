<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lifetime Awards paid in cash with the monthly payout (the client,
 * 2026-11-10). A milestone is now the entitlement behind one awards_credit
 * wallet row, and carries the evidence that links it to product sales
 * (hard rule 2; R-128):
 *
 *  - achieved_month         the month the rank was first achieved;
 *  - rank_qualification_id  the rank_qualifications row it was earned on
 *                           (advisory — a rebuild rewrites that row, and the
 *                           milestone with it; no FK on purpose);
 *  - personal_bv_paise, left_genos_bv_paise, right_genos_bv_paise
 *                           the BV snapshot at creation (Genos BV is recorded
 *                           by the qualification row for ranks 1–2 only);
 *  - credited_at            when the awards_credit row was written.
 *
 * The status ENUM gains 'credited'. SQLite stores the column as a string
 * (the CHECK list of a Blueprint enum is not widened by any migration here),
 * so only MySQL needs the DDL. Schema only: no row moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifetime_award_milestones', function (Blueprint $table): void {
            $table->date('achieved_month')->nullable()->after('triggered_month');
            $table->unsignedBigInteger('rank_qualification_id')->nullable()->after('achieved_month');
            $table->bigInteger('personal_bv_paise')->nullable()->after('rank_qualification_id');
            $table->bigInteger('left_genos_bv_paise')->nullable()->after('personal_bv_paise');
            $table->bigInteger('right_genos_bv_paise')->nullable()->after('left_genos_bv_paise');
            $table->timestamp('credited_at')->nullable()->after('status');

            $table->index('rank_qualification_id', 'idx_lifetime_award_qualification');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE lifetime_award_milestones MODIFY COLUMN status ENUM('pending','credited','delivered','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Never guess: narrowing the enum under a credited award would
            // rewrite or reject it. Refuse while any exists.
            $credited = (int) DB::table('lifetime_award_milestones')->where('status', 'credited')->count();

            if ($credited > 0) {
                throw new RuntimeException("Cannot narrow lifetime_award_milestones.status: {$credited} row(s) carry 'credited'.");
            }

            DB::statement("ALTER TABLE lifetime_award_milestones MODIFY COLUMN status ENUM('pending','delivered','cancelled') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('lifetime_award_milestones', function (Blueprint $table): void {
            $table->dropIndex('idx_lifetime_award_qualification');
            $table->dropColumn([
                'achieved_month',
                'rank_qualification_id',
                'personal_bv_paise',
                'left_genos_bv_paise',
                'right_genos_bv_paise',
                'credited_at',
            ]);
        });
    }
};
