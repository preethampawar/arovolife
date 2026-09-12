<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan §6: every Action Center count must be an indexed query. The fulfilment
 * clocks filter orders by status plus a timestamp, and the transfer clock by
 * the dispatch/receipt pair; none of those pairs was indexed before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'packed_at'], 'idx_orders_status_packed_at');
            $table->index(['status', 'shipped_at'], 'idx_orders_status_shipped_at');
        });

        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->index(['dispatched_at', 'received_at'], 'idx_stock_transfers_dispatch_receipt');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_status_packed_at');
            $table->dropIndex('idx_orders_status_shipped_at');
        });

        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropIndex('idx_stock_transfers_dispatch_receipt');
        });
    }
};
