<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-month frozen economics of the Fortune Bonus pool (KP 2026-08-07).
 *
 * Pool = pool_rate_bp (default 5%) of the month's company-wide BV — the same
 * signed bv_ledger_entries sum every other pool uses
 * (GsbDailyPoolService::companyBvPaiseBetween), so the GSB, MSB, GBB, Rank and
 * Fortune pools can never disagree on what a period's BV was. This replaces the
 * old fixed per-matrix-level rupee payout, which was not a pool at all.
 *
 * The point value is that pool ÷ the month's total FB points, floored to whole
 * rupees. FB points are earned from the enrolled downline in the monthly 3×9
 * Fortune matrix (relative level 1–3 → 9 points, then 8/7/6/5/4/3 down to
 * level 9), so only enrolled participants sit in the denominator.
 *
 * The row is written once per month BEFORE any credit and never recomputed — a
 * re-run after more BV or more enrolments have landed prices against this
 * snapshot, so the month's economics never move under a distributor who was
 * already paid.
 *
 * leftover_paise is the flooring remainder: pool − (point value × total
 * points). It is normally small and positive, and equals the whole pool in a
 * month whose point value floors to ₹0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fortune_monthly_pools', function (Blueprint $table) {
            $table->id();
            $table->date('month_start')->unique();
            $table->bigInteger('company_bv_paise');
            $table->unsignedInteger('pool_rate_bp');
            $table->bigInteger('pool_paise');
            $table->unsignedBigInteger('total_points');
            $table->unsignedBigInteger('point_value_paise');
            $table->bigInteger('payout_paise');
            $table->bigInteger('leftover_paise');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fortune_monthly_pools');
    }
};
