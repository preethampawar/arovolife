<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0009 build step 1:
 * - Add `refund_approved` to orders.status enum (Phase-2 terminal refund state).
 * - Add `refund_approved_at` timestamp to orders.
 * - Reconcile return_requests: expand reason enum to match BuybackMatrix::REASONS,
 *   make order_item_id + qty nullable (order-level cooling-off returns have no item).
 */
return new class extends Migration
{
    /** Full status enum list including the new refund_approved value. */
    private const ORDERS_STATUS_WITH_REFUND_APPROVED = [
        'draft', 'placed', 'paid', 'ready_to_ship', 'shipped', 'delivered',
        'confirmed', 'cancelled',
        'refund_requested', 'refund_inspection', 'refund_approved', 'refunded',
    ];

    /** Status enum WITHOUT refund_approved (used in down()). */
    private const ORDERS_STATUS_WITHOUT_REFUND_APPROVED = [
        'draft', 'placed', 'paid', 'ready_to_ship', 'shipped', 'delivered',
        'confirmed', 'cancelled',
        'refund_requested', 'refund_inspection', 'refunded',
    ];

    public function up(): void
    {
        // MySQL: raw MODIFY COLUMN is most efficient on large tables.
        // SQLite: Schema Builder recreates the table, updating the CHECK constraint.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'draft','placed','paid','ready_to_ship','shipped','delivered',
                'confirmed','cancelled',
                'refund_requested','refund_inspection','refund_approved','refunded'
            ) NOT NULL DEFAULT 'draft'");
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->enum('status', self::ORDERS_STATUS_WITH_REFUND_APPROVED)->default('draft')->change();
            });
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dateTime('refund_approved_at', 3)->nullable()->after('refunded_at');
        });

        // Expand return_requests.reason to BuybackMatrix reasons.
        // No rows exist yet (scaffold-only, no services wired), so the MODIFY is safe.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `return_requests` MODIFY COLUMN `reason` ENUM(
                'cooling_off','damage','dissatisfaction','general_buyback','termination_buyback'
            ) NOT NULL");
        } else {
            Schema::table('return_requests', function (Blueprint $table): void {
                $table->enum('reason', [
                    'cooling_off', 'damage', 'dissatisfaction', 'general_buyback', 'termination_buyback',
                ])->change();
            });
        }

        // Make order_item_id + qty nullable — cooling-off returns are order-level.
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_item_id')->nullable()->change();
            $table->unsignedInteger('qty')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('refund_approved_at');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'draft','placed','paid','ready_to_ship','shipped','delivered',
                'confirmed','cancelled',
                'refund_requested','refund_inspection','refunded'
            ) NOT NULL DEFAULT 'draft'");

            DB::statement("ALTER TABLE `return_requests` MODIFY COLUMN `reason` ENUM(
                'dissatisfaction','damaged','defective','wrong_item','other'
            ) NOT NULL");
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->enum('status', self::ORDERS_STATUS_WITHOUT_REFUND_APPROVED)->default('draft')->change();
            });

            Schema::table('return_requests', function (Blueprint $table): void {
                $table->enum('reason', [
                    'dissatisfaction', 'damaged', 'defective', 'wrong_item', 'other',
                ])->change();
            });
        }

        Schema::table('return_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_item_id')->nullable(false)->change();
            $table->unsignedInteger('qty')->nullable(false)->change();
        });
    }
};
