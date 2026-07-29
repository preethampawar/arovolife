<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-day frozen economics of the GSB pro-rated pool (KP 2026-07-29).
 *
 * Pool = pool_rate_bp of the day's company-wide BV (signed bv_ledger_entries
 * sum). Slabs 1–2 are paid fixed out of it first; the remainder ÷ the day's
 * total slab 3–7 scores (floored to whole rupees, capped at the fixed score
 * value) is the variable score value applied to every slab 3–7 achiever.
 *
 * The row is written once per cut-off date BEFORE any credit and never
 * recomputed — single-distributor re-runs price against this snapshot, and the
 * admin "Input & Output Per Day" report reads it verbatim.
 *
 * leftover_paise may be negative on a starved day: slabs 1–2 are always paid in
 * full even when their fixed total alone exceeds the pool (KP-approved).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsb_daily_pools', function (Blueprint $table) {
            $table->id();
            $table->date('cutoff_date')->unique();
            $table->bigInteger('company_bv_paise');
            $table->unsignedInteger('pool_rate_bp');
            $table->bigInteger('pool_paise');
            $table->bigInteger('fixed_payout_paise');
            $table->unsignedBigInteger('variable_total_score');
            $table->unsignedBigInteger('variable_score_value_cap_paise');
            $table->unsignedBigInteger('variable_score_value_paise');
            $table->bigInteger('variable_payout_paise');
            $table->bigInteger('leftover_paise');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsb_daily_pools');
    }
};
