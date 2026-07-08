<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GSB personal-BV weaker-leg topup ledger (2026-07-08).
 *
 * Before each day's GSB cut-off, the distributor's own day-of order BV is
 * added to their weaker Genos leg to help them reach a slab. Each per-order
 * topup is recorded here; reversed_at is set when the originating order is
 * cancelled within the cooling-off window so the BV is deducted from the same
 * side it was credited to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsb_personal_bv_topups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('bv_paise');
            $table->string('side', 1);        // 'L' or 'R' — the weaker side at topup time
            $table->date('date');             // GSB cutoff date this topup was applied for
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // One topup row per order (applied once per lifetime — the order
            // always targets the same side on the same cutoff date).
            $table->unique('order_id', 'uniq_gsb_topup_order');

            $table->index(['distributor_id', 'date'], 'idx_gsb_topup_dist_date');

            $table->foreign('distributor_id', 'fk_gsb_topup_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
            $table->foreign('order_id', 'fk_gsb_topup_order')
                ->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsb_personal_bv_topups');
    }
};
