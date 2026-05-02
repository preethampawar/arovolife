<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique('uniq_customers_user')->constrained('users')->nullOnDelete();
            $table->foreignId('distributor_id')->nullable()->unique('uniq_customers_distributor')->constrained('distributors')->nullOnDelete();
            $table->string('display_name', 128);
            $table->string('email_hash', 64)->nullable()->unique('uniq_customers_email_hash');
            $table->string('email_enc', 512)->nullable();
            $table->string('phone_hash', 64)->nullable();
            $table->string('phone_enc', 128)->nullable();
            $table->char('phone_last4', 4)->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->dateTime('claimed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index('phone_hash', 'idx_customers_phone_hash');
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->enum('kind', ['billing', 'shipping'])->default('shipping');
            $table->string('name', 150);
            $table->string('phone_e164', 20);
            $table->string('line1', 255);
            $table->string('line2', 255)->nullable();
            $table->string('city', 100);
            $table->string('state', 64);
            $table->string('pincode', 10);
            $table->char('country', 2)->default('IN');
            $table->boolean('is_default')->default(false);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['customer_id', 'kind'], 'idx_customer_addresses_kind');
        });

        Schema::create('attribution_touches', function (Blueprint $table): void {
            $table->id();
            $table->string('anonymous_key', 64);
            $table->string('ref_adn', 16);
            $table->foreignId('distributor_id')->nullable()->constrained('distributors')->nullOnDelete();
            $table->string('landing_url', 500)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->dateTime('occurred_at', 3);
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['anonymous_key', 'occurred_at'], 'idx_attr_touch_key_time');
        });

        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('anonymous_key', 64)->nullable();
            $table->string('ref_adn_snapshot', 16)->nullable();
            $table->dateTime('expires_at', 3);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index('anonymous_key', 'idx_carts_anon');
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->bigInteger('unit_price_paise');
            $table->bigInteger('bv_paise');
            $table->bigInteger('pv_paise');
            $table->unsignedInteger('gst_rate_bp');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['cart_id', 'product_variant_id'], 'uniq_cart_items_variant');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no', 24)->unique('uniq_orders_order_no');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('attributed_distributor_id')->nullable()->constrained('distributors')->nullOnDelete();
            $table->enum('attribution_source', ['cookie', 'logged_in', 'direct', 'admin'])->default('direct');
            $table->enum('status', [
                'draft', 'placed', 'paid', 'ready_to_ship', 'shipped',
                'delivered', 'confirmed', 'cancelled',
                'refund_requested', 'refund_inspection', 'refunded',
            ])->default('draft');
            $table->boolean('self_consumption')->default(false);

            $table->bigInteger('subtotal_paise')->default(0);
            $table->bigInteger('gst_paise')->default(0);
            $table->bigInteger('discount_paise')->default(0);
            $table->bigInteger('shipping_paise')->default(0);
            $table->bigInteger('total_paise')->default(0);

            $table->string('ship_name', 150)->nullable();
            $table->string('ship_phone_e164', 20)->nullable();
            $table->string('ship_line1', 255)->nullable();
            $table->string('ship_line2', 255)->nullable();
            $table->string('ship_city', 100)->nullable();
            $table->string('ship_state', 64)->nullable();
            $table->string('ship_pincode', 10)->nullable();

            $table->dateTime('placed_at', 3)->nullable();
            $table->dateTime('paid_at', 3)->nullable();
            $table->dateTime('shipped_at', 3)->nullable();
            $table->dateTime('delivered_at', 3)->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->dateTime('refunded_at', 3)->nullable();

            $table->string('idempotency_key', 96)->unique('uniq_orders_idempotency');
            $table->foreignId('tnc_of_sale_consent_id')->nullable()->constrained('consents')->nullOnDelete();

            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['attributed_distributor_id', 'status'], 'idx_orders_attr_status');
            $table->index(['customer_id', 'status'], 'idx_orders_cust_status');
            $table->index(['status', 'delivered_at'], 'idx_orders_status_delivered');
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('product_name_snapshot', 255);
            $table->string('variant_sku_snapshot', 80);
            $table->string('hsn_code_snapshot', 16);
            $table->unsignedInteger('qty');
            $table->bigInteger('unit_price_paise');
            $table->bigInteger('bv_paise');
            $table->bigInteger('pv_paise');
            $table->unsignedInteger('gst_rate_bp');
            $table->bigInteger('taxable_value_paise');
            $table->bigInteger('gst_paise');
            $table->bigInteger('line_total_paise');
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index('order_id', 'idx_order_items_order');
        });

        Schema::create('order_cooling_off', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique('uniq_order_cooling_off_order')->constrained('orders')->cascadeOnDelete();
            $table->dateTime('opened_at', 3);
            $table->dateTime('ends_at', 3);
            $table->enum('status', ['open', 'expired', 'cancelled'])->default('open');
            $table->unsignedBigInteger('refund_trigger_event_id')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['status', 'ends_at'], 'idx_cooling_off_status_ends');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cooling_off');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('attribution_touches');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
