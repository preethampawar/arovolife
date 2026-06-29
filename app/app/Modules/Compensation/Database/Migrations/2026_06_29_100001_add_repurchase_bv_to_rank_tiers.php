<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-rank monthly repurchase BV obligation (paise), admin-configurable.
     * KP 2026-06-27/28: R1 1,000 … R9 2,300 BV (non-ranked 600 lives as a
     * scalar setting comp.repurchase.non_ranked_bv_paise). A distributor must
     * complete this much self-purchase BV per cycle to keep income-eligible.
     */
    public function up(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->unsignedBigInteger('repurchase_bv_paise')->default(0)->after('carry_forward_months');
        });
    }

    public function down(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->dropColumn('repurchase_bv_paise');
        });
    }
};
