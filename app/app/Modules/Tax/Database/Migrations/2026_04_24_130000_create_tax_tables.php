<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique('uniq_invoices_order')->constrained('orders')->restrictOnDelete();
            $table->string('invoice_no', 32)->unique('uniq_invoices_number');
            $table->string('irn', 64)->nullable();
            $table->dateTime('issued_at', 3);

            $table->string('seller_gstin', 15)->nullable();
            $table->string('seller_state', 64);
            $table->string('buyer_gstin', 15)->nullable();
            $table->string('buyer_state', 64);
            $table->string('place_of_supply', 64);

            $table->bigInteger('subtotal_paise');
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->bigInteger('cess_paise')->default(0);
            $table->bigInteger('total_paise');

            $table->string('pdf_hash_sha256', 64)->nullable();
            $table->string('pdf_storage_key', 255)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->string('hsn_code', 16);
            $table->unsignedInteger('qty');
            $table->bigInteger('taxable_value_paise');
            $table->unsignedInteger('gst_rate_bp');
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->dateTime('created_at', 3)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
