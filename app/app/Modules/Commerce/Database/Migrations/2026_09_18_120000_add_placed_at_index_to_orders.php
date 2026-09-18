<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order-date counterpart of `idx_orders_status_shipped_at`.
 *
 * `SalesReportService` reports on two bases. The default, `BASIS_SHIPPED`,
 * filters `status IN (...)` plus a range on `orders.shipped_at` and is already
 * served by `idx_orders_status_shipped_at`; the profit report uses it because
 * cost is stamped at pack time. `BASIS_ORDERED` filters the same statuses
 * against `orders.placed_at`, and nothing indexed that column at all — so the
 * one question a dashboard actually asks ("what came in today") was the one
 * that scanned the table.
 *
 * Purely additive: an index, no column and no data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'placed_at'], 'idx_orders_status_placed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_status_placed_at');
        });
    }
};
