<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the last trace of the "1+2 rule" plan config (KP 2026-08-05, replaced
 * by the AO-GO offer; user directive 2026-08-07 to remove superseded logic).
 *
 * `rank_tiers.carry_forward_months` was seeded 0 for every rank, but the
 * qualification engine still read it, so an admin editing the field on
 * /admin/compensation/plan-settings could have silently revived a retired
 * bonus rule. RankQualificationService no longer creates carry-forward
 * qualifications at all and the field is gone from the rank form.
 *
 * `rank_qualifications.is_carry_forward` and its historical rows are KEPT and
 * still read: the Q-Period count excludes them, the Rank Bonus does not pay
 * them, and a rank-2 qualification still voids a pending rank-1 carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rank_tiers', 'carry_forward_months')) {
            Schema::table('rank_tiers', function (Blueprint $table) {
                $table->dropColumn('carry_forward_months');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rank_tiers', 'carry_forward_months')) {
            Schema::table('rank_tiers', function (Blueprint $table) {
                $table->unsignedTinyInteger('carry_forward_months')->default(0)->after('structural_qualifiers_per_side');
            });
        }
    }
};
