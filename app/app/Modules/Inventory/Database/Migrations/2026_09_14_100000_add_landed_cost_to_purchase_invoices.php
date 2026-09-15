<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landed cost, part 1 — the charges that turn a supplier price into a real
 * cost of goods.
 *
 * Until now a GRN carried only `subtotal_paise` / `gst_paise` / `total_paise`,
 * so `stock_batches.unit_cost_paise` was the bare supplier line price. Freight,
 * insurance and handling were folded — by hand, with no audit trail — into
 * `product_variants.landing_price_paise`, which nothing in the codebase read.
 *
 * These four charge buckets are the input to LandedCostAllocator, which spreads
 * them across the GRN's lines and writes `landed_unit_cost_paise` per line.
 * That figure becomes the batch cost, and therefore the COGS stamped on every
 * sale.
 *
 * GST is deliberately NOT part of landed cost: it is recoverable input credit,
 * not a cost of the goods. It stays in its own column.
 *
 * Guards make this idempotent so a partially-migrated DB self-heals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_invoices', 'freight_paise')) {
                $table->bigInteger('freight_paise')->default(0)->after('subtotal_paise');
            }
            if (! Schema::hasColumn('purchase_invoices', 'insurance_paise')) {
                $table->bigInteger('insurance_paise')->default(0)->after('freight_paise');
            }
            if (! Schema::hasColumn('purchase_invoices', 'handling_paise')) {
                $table->bigInteger('handling_paise')->default(0)->after('insurance_paise');
            }
            if (! Schema::hasColumn('purchase_invoices', 'other_charges_paise')) {
                $table->bigInteger('other_charges_paise')->default(0)->after('handling_paise');
            }
            // subtotal + the four charge buckets, ex-GST. Stored rather than
            // derived so the purchase register and the Trading Account read one
            // number instead of re-adding five columns in four places.
            if (! Schema::hasColumn('purchase_invoices', 'landed_total_paise')) {
                $table->bigInteger('landed_total_paise')->default(0)->after('other_charges_paise');
            }
            if (! Schema::hasColumn('purchase_invoices', 'allocation_basis')) {
                $table->string('allocation_basis', 8)->default('value')->after('landed_total_paise');
            }
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_invoice_items', 'allocated_charges_paise')) {
                $table->bigInteger('allocated_charges_paise')->default(0)->after('taxable_value_paise');
            }
            // (taxable_value + allocated_charges) / qty, rounded. The per-unit
            // figure that becomes stock_batches.unit_cost_paise on post().
            if (! Schema::hasColumn('purchase_invoice_items', 'landed_unit_cost_paise')) {
                $table->bigInteger('landed_unit_cost_paise')->default(0)->after('allocated_charges_paise');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'freight_paise', 'insurance_paise', 'handling_paise',
                'other_charges_paise', 'landed_total_paise', 'allocation_basis',
            ]);
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table): void {
            $table->dropColumn(['allocated_charges_paise', 'landed_unit_cost_paise']);
        });
    }
};
