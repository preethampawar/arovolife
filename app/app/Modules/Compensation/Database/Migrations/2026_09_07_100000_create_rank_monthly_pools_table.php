<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-month, per-rank frozen economics of the Rank Bonus pool — the last
 * pool-based engine that had none.
 *
 * Pool = company BV (the signed bv_ledger_entries sum for the month, the same
 * figure GSB/MSB/GBB use) × envelope_bp × the rank's pool_pct. Rank 1 divides
 * that pool by points (payable achievers × RAP + Σ AO-GO points); ranks 2–9
 * split it equally among payable achievers.
 *
 * Every figure on this row is written once, BEFORE any credit, and never
 * recomputed. Without it RankBonusService recomputed the pool AND the qualifier
 * roster on every re-run: a ₹6,000 pool paid to two qualifiers at ₹3,000 each
 * re-priced to ₹2,000 × 3 the moment a third qualifier's hold cleared, paying
 * ₹8,000 out of ₹6,000 and leaving credited and non-credited rows of the same
 * month disagreeing on pool_paise / qualifier_count / point_value_paise.
 *
 * company_turnover_paise and pool_paise are SIGNED here: a refund-heavy month
 * has negative company BV and the signed truth belongs on the frozen row. The
 * per-distributor rank_bonus_results columns are unsigned, so the engine clamps
 * them there — this table is the audit record.
 *
 * payout_paise is the gross actually written across the month's roster rows —
 * the flooring remainder AND the shares of wallet-blocked achievers are both
 * excluded, because a blocked achiever keeps their place in the denominator but
 * is written with gross 0. leftover_paise is therefore pool_paise − payout_paise:
 * everything the pool did not spend, not only the rounding dust. It is never
 * negative by construction (payout can never exceed the pool), which is exactly
 * the invariant the freeze restores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_monthly_pools', function (Blueprint $table) {
            $table->id();
            $table->date('month_start');
            $table->unsignedTinyInteger('rank_number');

            // Pool inputs, frozen verbatim so the arithmetic can be re-read.
            $table->bigInteger('company_turnover_paise');
            $table->unsignedInteger('envelope_bp');
            $table->decimal('pool_pct', 8, 4);
            $table->bigInteger('pool_paise');

            // Denominator. rap_points is null for ranks 2–9 (equal split).
            $table->unsignedSmallInteger('rap_points')->nullable();
            $table->unsignedInteger('payable_count');
            $table->unsignedInteger('aogo_points');
            $table->unsignedInteger('total_points')->nullable();

            // Distribution.
            $table->unsignedBigInteger('point_value_paise')->nullable();
            $table->unsignedBigInteger('gross_per_qualifier_paise');
            $table->bigInteger('payout_paise');
            $table->bigInteger('leftover_paise');

            $table->timestamps();

            $table->unique(['month_start', 'rank_number'], 'uq_rank_pool_month_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_monthly_pools');
    }
};
