<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-month frozen economics of the Growth Booster Bonus pool.
 *
 * Pool = pool_rate_bp (default 5%) of the month's company-wide BV — the same
 * signed bv_ledger_entries sum the GSB and MSB pools use
 * (GsbDailyPoolService::companyBvPaiseBetween), so the three pools can never
 * disagree on what a period's BV was. The old "5% of order sales value" base is
 * gone: GBB is a BV pool like every other bonus.
 *
 * The point value is that pool ÷ the month's total payable AGP, floored to
 * whole rupees. Only payable participants sit in the denominator: repurchase
 * grace-window (held) distributors do, because they can still be released and
 * paid; post-grace suspended distributors do NOT, because they can never be
 * paid for the month and would otherwise dilute everyone else's value.
 *
 * The row is written once per month BEFORE any credit and never recomputed —
 * a re-run after more BV or more cut-offs have landed prices against this
 * snapshot, so the month's economics never move under a distributor who was
 * already paid.
 *
 * leftover_paise is the flooring remainder and is normally small and positive;
 * it can go negative when a later release credits AGP that was not in the
 * frozen denominator (accepted deviation, mirrors the GSB and MSB pools).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gbb_monthly_pools', function (Blueprint $table) {
            $table->id();
            $table->date('month_start')->unique();
            $table->bigInteger('company_bv_paise');
            $table->unsignedInteger('pool_rate_bp');
            $table->bigInteger('pool_paise');
            $table->unsignedBigInteger('total_agp');
            $table->unsignedBigInteger('point_value_paise');
            $table->bigInteger('payout_paise');
            $table->bigInteger('leftover_paise');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbb_monthly_pools');
    }
};
