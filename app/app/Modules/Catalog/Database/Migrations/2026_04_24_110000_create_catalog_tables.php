<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64)->unique('uniq_products_sku');
            $table->string('slug', 128)->unique('uniq_products_slug');
            $table->string('name', 255);
            $table->text('short_description')->nullable();
            $table->text('description')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('hsn_code', 16);
            $table->string('image_url', 500)->nullable();
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['status', 'category'], 'idx_products_status_category');
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('variant_sku', 80)->unique('uniq_product_variants_sku');
            $table->string('name', 150)->nullable();
            $table->json('attributes')->nullable();
            $table->unsignedInteger('weight_g')->default(0);
            $table->bigInteger('mrp_paise');
            $table->bigInteger('sale_price_paise');
            $table->bigInteger('cost_paise')->default(0);
            $table->bigInteger('bv_paise')->default(0);
            $table->bigInteger('pv_paise')->default(0);
            $table->unsignedInteger('gst_rate_bp')->default(1800); // basis points — 18% = 1800
            $table->enum('inventory_policy', ['track', 'no_track'])->default('track');
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index('product_id', 'idx_product_variants_product');
        });

        Schema::create('inventory_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('warehouse_code', 32)->default('DEFAULT');
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['product_variant_id', 'warehouse_code'], 'uniq_inventory_variant_warehouse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_levels');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
