<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-day frozen economics of the Mentorship Bonus pool (KP 2026-07-30).
 *
 * Pool = pool_rate_bp (default 3%) of the day's company-wide BV (the same
 * signed bv_ledger_entries sum the GSB pool uses). The point value is that
 * pool ÷ the day's total MSB score points, floored to whole rupees — there is
 * no cap and no fixed per-slab value any more, so the pool is distributed
 * among the day's earners and only the flooring remainder stays behind.
 *
 * The row is written once per cut-off date BEFORE any credit and never
 * recomputed — single-distributor re-runs price against this snapshot, and the
 * admin "MSB Input & Output Per Day" report reads it verbatim.
 *
 * leftover_paise is the flooring remainder and is normally small and positive;
 * it can go negative when an admin retry credits points that were not in the
 * frozen denominator (accepted deviation, mirrors the GSB pool).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msb_daily_pools', function (Blueprint $table) {
            $table->id();
            $table->date('cutoff_date')->unique();
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
        Schema::dropIfExists('msb_daily_pools');
    }
};
