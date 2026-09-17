<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the buyer's delivery choice a fact the order records, and gives a
 * collection its own fee column.
 *
 * Until now `arete_center_id IS NOT NULL` was the only trace that a buyer chose
 * to collect from an Arete Development Centre. That is fragile in a way that
 * matters: the FK is `nullOnDelete`, so deleting a centre silently converted a
 * collection order into a shipping order — one carrying the centre's postal
 * address in its `ship_*` columns, which the buyer never gave. `delivery_type`
 * records the choice itself and survives the centre (plan AD-1).
 *
 * `collection_fee_paise` is deliberately NOT a reuse of `shipping_paise`:
 * `ProfitReportService` aggregates that column as `shipping_collected_paise`
 * and `RefundOrder` refunds it on cooling-off, so merging the two would corrupt
 * both the P&L and the refund (plan AD-3). It defaults to 0, which is also the
 * launch position — a buyer who collects pays nothing for a delivery that never
 * happens. That is the R-94 fix.
 *
 * The status widen adds `awaiting_collection` between `shipped` and
 * `delivered`: the parcel is at the centre and the buyer has been told it is
 * ready. `delivered` then means collected, so the 30-day per-order cooling-off
 * clock still starts when the buyer actually takes possession (plan AD-7).
 */
return new class extends Migration
{
    /** The full enum after this migration, for the non-MySQL branch. */
    private const ORDER_STATUSES = [
        'draft', 'placed', 'paid', 'ready_to_ship', 'shipped',
        'awaiting_collection', 'delivered', 'confirmed', 'cancelled',
        'refund_requested', 'refund_inspection', 'refund_approved', 'refunded',
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('delivery_type', ['ship', 'collect'])
                ->default('ship')
                ->after('arete_center_id');
            $table->bigInteger('collection_fee_paise')
                ->default(0)
                ->after('shipping_paise');
        });

        // Every order that named a centre was a collection order. The column
        // did not exist when they were placed, so this backfill is the only
        // record of what those buyers chose.
        DB::table('orders')
            ->whereNotNull('arete_center_id')
            ->update(['delivery_type' => 'collect']);

        // MySQL: raw MODIFY COLUMN is most efficient on large tables.
        // SQLite: Schema Builder recreates the table, updating the CHECK
        // constraint. A bare ->change() on both drivers is NOT equivalent —
        // see 2026_06_18_000001, which widened this same column.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'draft','placed','paid','ready_to_ship','shipped',
                'awaiting_collection','delivered','confirmed','cancelled',
                'refund_requested','refund_inspection','refund_approved','refunded'
            ) NOT NULL DEFAULT 'draft'");
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->enum('status', self::ORDER_STATUSES)->default('draft')->change();
            });
        }
    }

    public function down(): void
    {
        // Narrowing the enum would fail on any row still holding the new value,
        // so park those back at `shipped` — the state they came from.
        DB::table('orders')
            ->where('status', 'awaiting_collection')
            ->update(['status' => 'shipped']);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'draft','placed','paid','ready_to_ship','shipped','delivered',
                'confirmed','cancelled',
                'refund_requested','refund_inspection','refund_approved','refunded'
            ) NOT NULL DEFAULT 'draft'");
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->enum('status', array_values(array_diff(
                    self::ORDER_STATUSES,
                    ['awaiting_collection'],
                )))->default('draft')->change();
            });
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['delivery_type', 'collection_fee_paise']);
        });
    }
};
