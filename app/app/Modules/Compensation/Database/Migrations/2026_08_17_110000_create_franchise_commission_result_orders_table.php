<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which sales each franchise commission was paid on (R-22).
 *
 * The result row's `order_count` and `base_paise` are an aggregate, and an
 * aggregate nobody can decompose is not a trace: re-running the query a year
 * later would not reproduce the figure once those orders have changed state.
 * Hard rule 2 requires every credit to point at product sales, so the sales
 * are recorded here, once, at the moment they are paid.
 *
 * `restrictOnDelete` on the ORDER, deliberately: this table is evidence, and a
 * cascade or a null-out there would erase the proof that the payment was
 * earned. The result row is this table's parent, so it cascades — deleting a
 * commission result is meant to take its own working with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchise_commission_result_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('result_id')
                ->constrained('franchise_commission_results')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // Net product value at the time of payment: subtotal less GST less
            // discount. Snapshotted because the order may be edited, refunded
            // or repriced afterwards and this is what was actually paid on.
            $table->bigInteger('base_paise');
            $table->dateTime('delivered_at', 3)->nullable();
            $table->timestamps();

            $table->unique(['result_id', 'order_id'], 'uniq_franchise_result_order');
            $table->index('order_id', 'idx_franchise_result_order_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_commission_result_orders');
    }
};
