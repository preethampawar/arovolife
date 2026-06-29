<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-rank Lifetime Awards budget (paise), admin-editable. KP 2026-06-28:
     * R1 ₹15,000 … R9 ₹2.25Cr. The itemised reward worths in
     * lifetime_award_rewards reconcile to this figure.
     */
    public function up(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->unsignedBigInteger('lifetime_award_budget_paise')->default(0)->after('repurchase_bv_paise');
        });
    }

    public function down(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->dropColumn('lifetime_award_budget_paise');
        });
    }
};
