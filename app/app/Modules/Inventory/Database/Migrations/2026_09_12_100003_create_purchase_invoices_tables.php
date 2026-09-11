<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The goods receipt and the supplier invoice are one document here (§10): the
 * ops flow is "supplier invoice arrives → stock in", and a separate GRN would
 * double the data entry for a small team.
 *
 * Purchase lines are tax-EXCLUSIVE — supplier invoices quote ex-GST — which is
 * the opposite of the catalogue, whose prices are GST-inclusive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('grn_no', 24)->unique('uniq_purchase_invoices_grn_no');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('warehouse_code', 32);
            $table->string('supplier_invoice_no', 64);
            $table->date('supplier_invoice_date');
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->bigInteger('subtotal_paise')->default(0);
            $table->bigInteger('gst_paise')->default(0);
            $table->bigInteger('total_paise')->default(0);
            $table->text('notes')->nullable();
            $table->dateTime('posted_at', 3)->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            // The same supplier invoice cannot be entered twice.
            $table->unique(['supplier_id', 'supplier_invoice_no'], 'uniq_purchase_invoices_supplier_invoice');
            $table->index('warehouse_code', 'idx_purchase_invoices_warehouse');
            $table->index('status', 'idx_purchase_invoices_status');
        });

        Schema::create('purchase_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('batch_no', 64);
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedInteger('qty');
            $table->bigInteger('unit_cost_paise');
            $table->unsignedInteger('gst_rate_bp');
            $table->bigInteger('taxable_value_paise');
            $table->bigInteger('gst_paise');
            $table->bigInteger('line_total_paise');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index('purchase_invoice_id', 'idx_purchase_invoice_items_invoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
        Schema::dropIfExists('purchase_invoices');
    }
};
