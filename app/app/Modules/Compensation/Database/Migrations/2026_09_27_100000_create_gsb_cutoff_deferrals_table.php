<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A GSB cut-off the full nightly run could not judge (E5 redesign, 2026-09-27).
 *
 * When `repurchase:evaluate` throws for a distributor, the run skips them and
 * keeps their previous verdict (the client's skip-and-continue decision). The
 * full cut-off then still COMPUTES their day — so their matched share is
 * reserved in the day's frozen GSB pool and MSB denominator — but does not
 * settle it: a stale verdict must never pay. This row is the record of that
 * owed day. There is deliberately no `gsb_cutoff_results` row until the day is
 * backfilled: the next full run settles open deferrals in date order, before it
 * computes its own day, so the rolling carry-forward store is advanced in order
 * and the out-of-order guard is never met.
 *
 * The reserved figures are snapshotted for the report only; the backfill
 * prices against the day's frozen pool, never against these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsb_cutoff_deferrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('cutoff_date');
            $table->string('cause', 32);
            $table->unsignedBigInteger('evaluate_run_id')->nullable();

            // What the full run held back for this day, at the stale verdict.
            $table->unsignedTinyInteger('reserved_slab')->nullable();
            $table->bigInteger('reserved_gsb_paise')->default(0);
            $table->unsignedInteger('reserved_msb_points')->default(0);

            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 16)->nullable();
            $table->unsignedBigInteger('gsb_cutoff_result_id')->nullable();

            $table->timestamps();

            $table->unique(['distributor_id', 'cutoff_date'], 'uniq_gsb_deferral_day');
            $table->index(['resolved_at', 'cutoff_date'], 'idx_gsb_deferral_open');

            $table->foreign('distributor_id', 'fk_gsb_deferral_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();

            // Nulled, not cascaded: the R-91 §14c production rebuild deletes
            // result rows by design, and the record that a day was deferred and
            // how it was resolved must survive it.
            $table->foreign('gsb_cutoff_result_id', 'fk_gsb_deferral_result')
                ->references('id')->on('gsb_cutoff_results')->nullOnDelete();
            $table->foreign('evaluate_run_id', 'fk_gsb_deferral_run')
                ->references('id')->on('engine_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsb_cutoff_deferrals');
    }
};
