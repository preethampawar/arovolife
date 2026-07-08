<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-rank weaker-leg personal-BV top-up cap (paise), admin-editable.
     * KP 2026-06-28: Ranks 1 & 2 only — R1 15,000 BV, R2 30,000 BV. Up to this
     * much of the distributor's personal purchase BV in the qualifying month
     * may be added to their weaker Genos leg to help meet the rank's group-BV
     * match. 0 = no top-up (Ranks 3-9).
     */
    public function up(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->unsignedBigInteger('weaker_leg_topup_bv_paise')->default(0)->after('group_bv_required_paise');
        });
    }

    public function down(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->dropColumn('weaker_leg_topup_bv_paise');
        });
    }
};
