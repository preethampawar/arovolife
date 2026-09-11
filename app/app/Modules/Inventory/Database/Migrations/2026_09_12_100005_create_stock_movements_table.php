<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPEND-ONLY. Same principle as the wallet ledger (ADR-0004): stock is never a
 * mutable integer, it is the sum of its movements. `inventory_levels.on_hand`
 * and `stock_batches.qty_on_hand` are projections written in the same
 * transaction; nothing in the codebase updates or deletes a row here — a
 * mistake is corrected by posting its reversal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('warehouse_code', 32);
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->enum('type', [
                'purchase_in', 'purchase_reversal', 'sale_out', 'sale_reversal',
                'transfer_out', 'transfer_in', 'return_in',
                'adjustment_in', 'adjustment_out', 'write_off', 'opening',
            ]);
            // Signed: +in / −out, never 0.
            $table->integer('qty');
            $table->bigInteger('unit_cost_paise')->default(0);
            // 'order_item' | 'purchase_invoice_item' | 'stock_transfer_item' | 'return_request' | 'stock_adjustment'
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('occurred_at', 3);
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['product_variant_id', 'warehouse_code', 'occurred_at'], 'idx_sm_variant_wh_time');
            $table->index(['reference_type', 'reference_id'], 'idx_sm_reference');
            $table->index(['type', 'occurred_at'], 'idx_sm_type_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
