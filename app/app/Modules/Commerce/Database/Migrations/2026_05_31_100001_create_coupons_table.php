<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coupons')) {
            return;
        }

        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique('uniq_coupons_code');
            $table->string('description', 255)->nullable();
            // percent → `value` is whole percent (e.g. 10 = 10%)
            // fixed   → `value` is a flat amount in paise
            $table->enum('type', ['percent', 'fixed']);
            $table->unsignedBigInteger('value');
            // Cap for percent coupons (max rupee discount), in paise. Null = no cap.
            $table->bigInteger('max_discount_paise')->nullable();
            // Minimum cart subtotal (paise) before the coupon applies.
            $table->bigInteger('min_purchase_paise')->default(0);
            // Restrict eligibility: whole cart, a category, or a single product.
            $table->enum('scope', ['all', 'category', 'product'])->default('all');
            // category_id or product_id depending on scope (null for 'all').
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->dateTime('starts_at', 3)->nullable();
            $table->dateTime('ends_at', 3)->nullable();
            // Total redemptions allowed across all customers (null = unlimited).
            $table->unsignedInteger('usage_limit')->nullable();
            // Per-customer redemption cap (null = unlimited). Powers "new user"
            // / one-per-customer style coupons.
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['status', 'code'], 'idx_coupons_status_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
