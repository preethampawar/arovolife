<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `shipments` from a scaffold into the row a courier and a collection
 * centre both write to.
 *
 * Two journeys share this table. A home delivery is consigned to the buyer. A
 * collection order is consigned to the Arete Development Centre the buyer
 * chose, acknowledged by the centre when it arrives, and released to the buyer
 * against a collection code (plan AD-5). Only the consignee differs, which is
 * why one table carries both.
 *
 * Deliberately NOT added: a second hash column for the handover proof.
 * `pod_hash_sha256` has existed on this table since 2026-04-24 and nothing has
 * ever written or read it — it is the proof-of-delivery column, and a collection
 * handover is a proof of delivery. The earlier draft of the plan added a
 * `handover_otp_hash` beside it; that was a self-contradiction caught in review
 * (M1) and there is one proof column, not two.
 *
 * `uniq_shipments_order` makes explicit what `OrderFulfilmentService::pack()`
 * has always assumed — one shipment per order. The assumption was guarded only
 * by `orders.packed_at` being null, never by the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // pack() has always assumed one shipment per order but nothing enforced
        // it. Fail with the offending order ids rather than a duplicate-key
        // error that says nothing about which rows need reconciling first.
        $duplicates = DB::table('shipments')
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('order_id')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot add uniq_shipments_order: these orders have more than one shipment row and must be reconciled first: '
                .implode(', ', $duplicates)
            );
        }

        Schema::table('shipments', function (Blueprint $table): void {
            // Which courier moved it. 'manual' is what every existing row is:
            // a carrier name and AWB an admin typed in by hand.
            $table->string('gateway', 16)->default('manual')->after('carrier_code');
            $table->string('gateway_shipment_id', 64)->nullable()->after('gateway');
            $table->string('label_url', 512)->nullable()->after('awb_no');

            // Collection leg. Null on a home delivery.
            $table->unsignedBigInteger('arete_center_id')->nullable()->after('warehouse_code');
            $table->dateTime('consigned_at', 3)->nullable()->after('dispatched_at');
            $table->dateTime('at_centre_at', 3)->nullable()->after('consigned_at');
            $table->dateTime('collected_at', 3)->nullable()->after('delivered_at');
            $table->foreignId('collected_by_user_id')->nullable()->after('collected_at')
                ->constrained('users')->nullOnDelete();

            $table->foreign('arete_center_id', 'fk_shipments_arete_center')
                ->references('id')->on('arete_centers')->nullOnDelete();

            $table->unique('order_id', 'uniq_shipments_order');
            // NULLs compare distinct in a unique index on both drivers, so every
            // existing manual row (gateway_shipment_id NULL) coexists happily.
            $table->unique(['gateway', 'gateway_shipment_id'], 'uniq_shipments_gateway_ref');
            $table->index(['arete_center_id', 'status'], 'idx_shipments_centre_status');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `shipments` MODIFY COLUMN `status` ENUM(
                'created','picked','dispatched','at_centre','delivered','returned_to_origin'
            ) NOT NULL DEFAULT 'created'");
        } else {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->enum('status', [
                    'created', 'picked', 'dispatched', 'at_centre', 'delivered', 'returned_to_origin',
                ])->default('created')->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('shipments')
            ->where('status', 'at_centre')
            ->update(['status' => 'dispatched']);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `shipments` MODIFY COLUMN `status` ENUM(
                'created','picked','dispatched','delivered','returned_to_origin'
            ) NOT NULL DEFAULT 'created'");
        } else {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->enum('status', [
                    'created', 'picked', 'dispatched', 'delivered', 'returned_to_origin',
                ])->default('created')->change();
            });
        }

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropForeign('fk_shipments_arete_center');
            $table->dropConstrainedForeignId('collected_by_user_id');
            $table->dropUnique('uniq_shipments_order');
            $table->dropUnique('uniq_shipments_gateway_ref');
            $table->dropIndex('idx_shipments_centre_status');
            $table->dropColumn([
                'gateway', 'gateway_shipment_id', 'label_url',
                'arete_center_id', 'consigned_at', 'at_centre_at', 'collected_at',
            ]);
        });
    }
};
