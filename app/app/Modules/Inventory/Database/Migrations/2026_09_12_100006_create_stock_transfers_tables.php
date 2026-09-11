<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock moving between two warehouses. There is deliberately no in-transit
 * location (§10): between dispatch and receipt the goods belong to no
 * warehouse's on-hand, and the transfer register reports the in-transit
 * quantity as dispatched − received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no', 24)->unique('uniq_stock_transfers_no');
            $table->string('from_warehouse_code', 32);
            $table->string('to_warehouse_code', 32);
            $table->enum('status', ['draft', 'dispatched', 'received', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('dispatched_at', 3)->nullable();
            $table->dateTime('received_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['status', 'created_at'], 'idx_stock_transfers_status_time');
            $table->index('from_warehouse_code', 'idx_stock_transfers_from');
            $table->index('to_warehouse_code', 'idx_stock_transfers_to');
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            // The source batch at the sending warehouse: a transfer moves a
            // specific batch, so expiry and cost travel with the goods.
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index('stock_transfer_id', 'idx_stock_transfer_items_transfer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
