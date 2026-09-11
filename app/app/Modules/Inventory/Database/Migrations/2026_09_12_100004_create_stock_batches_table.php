<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One batch of one variant at one warehouse. `qty_on_hand` is a projection of
 * `stock_movements` for the batch, written in the same transaction as every
 * movement; `inventory:verify` proves the two still agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('warehouse_code', 32);
            $table->string('batch_no', 64);
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->bigInteger('unit_cost_paise')->default(0);
            $table->integer('qty_on_hand')->default(0);
            // First receipt into this warehouse — the FEFO tie-break for
            // batches with no expiry, and the ageing clock.
            $table->dateTime('received_at', 3);
            // 'purchase_invoice_item' | 'stock_transfer_item' | 'return_request' | 'adjustment'
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->dateTime('expiry_alerted_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['product_variant_id', 'warehouse_code', 'batch_no'], 'uniq_stock_batches_variant_wh_batch');
            $table->index(['warehouse_code', 'expiry_date'], 'idx_stock_batches_wh_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
