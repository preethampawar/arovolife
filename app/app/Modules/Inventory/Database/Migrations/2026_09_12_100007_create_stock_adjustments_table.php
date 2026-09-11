<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only way stock changes without a purchase, a sale, a return or a
 * transfer: a physical count correction, damage, expiry, loss or a sample.
 * Every row is audited and carries mandatory notes — an unexplained
 * adjustment is indistinguishable from theft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('adjustment_no', 24)->unique('uniq_stock_adjustments_no');
            $table->string('warehouse_code', 32);
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            // Signed, never 0.
            $table->integer('qty_delta');
            $table->enum('reason', ['count_correction', 'damaged', 'expired', 'theft_loss', 'sample', 'other']);
            $table->text('notes');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('occurred_at', 3);
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['warehouse_code', 'occurred_at'], 'idx_stock_adjustments_wh_time');
            $table->index('reason', 'idx_stock_adjustments_reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
