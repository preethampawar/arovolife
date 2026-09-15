<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landed cost, part 2 — every movement of a variant's landing price.
 *
 * `product_variants.landing_price_paise` used to be a hand-typed number with
 * no history, which made it useless as a cost basis: a profit figure computed
 * against it would silently change the day somebody edited the product form,
 * and nothing would record that it had. Once the price is derived from real
 * GRNs it becomes evidence, and evidence needs a trail.
 *
 * Append-only, like `stock_movements`: a correction is a new row, never an
 * edit of an old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->bigInteger('old_paise')->default(0);
            $table->bigInteger('new_paise')->default(0);
            // grn      — recomputed from a posted or cancelled goods receipt
            // manual   — typed by an admin, only possible before any GRN exists
            // backfill — seeded from existing batches when this feature shipped
            $table->enum('source', ['grn', 'manual', 'backfill'])->default('grn');
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['product_variant_id', 'created_at'], 'idx_lph_variant_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_price_history');
    }
};
