<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Franchise module removed — it duplicated the ADC module under a different name.
 *
 * 1. Drop franchise commission result tables (child-first to satisfy FK constraints).
 * 2. Remove orders.franchise_id and replace it with orders.arete_center_id
 *    so that collection-point attribution flows to an Arete Development Center.
 * 3. Drop the franchises table.
 * 4. Remove the franchise_credit enum value from wallet_ledger_entries.type.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Franchise commission pivot + results ──────────────────────────
        Schema::dropIfExists('franchise_commission_result_orders');
        Schema::dropIfExists('franchise_commission_results');

        // ── 2. Swap orders.franchise_id → arete_center_id ───────────────────
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['franchise_id']);
            $table->dropColumn('franchise_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('arete_center_id')->nullable()->after('attributed_distributor_id');
            $table->foreign('arete_center_id', 'fk_orders_arete_center')
                ->references('id')->on('arete_centers')->nullOnDelete();
            $table->index('arete_center_id', 'idx_orders_arete_center');
        });

        // ── 3. Franchises table ──────────────────────────────────────────────
        Schema::dropIfExists('franchises');

        // ── 4. Remove franchise_credit from wallet_ledger_entries.type enum ──
        DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used') NOT NULL");
    }

    public function down(): void
    {
        // Restore franchise_credit enum value
        DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','franchise_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used') NOT NULL");

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign('fk_orders_arete_center');
            $table->dropIndex('idx_orders_arete_center');
            $table->dropColumn('arete_center_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('franchise_id')->nullable()->after('attributed_distributor_id');
        });
    }
};
