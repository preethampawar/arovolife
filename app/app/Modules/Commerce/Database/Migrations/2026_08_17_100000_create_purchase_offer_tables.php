<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two purchase offers KP specified on 2026-06-26, for distributors who
 * hold no rank:
 *
 *  1. **50% product** — repurchase ≥ 1,000 BV in a month and one
 *     company-specified product is available at half the distributor price.
 *  2. **20% redeem points** — hold ≥ 1,000 BV for six consecutive months and
 *     20% of the BV accumulated over those months is granted as redeem points,
 *     one point being one rupee off a future purchase.
 *
 * The "joining" trigger in the original WhatsApp description is deliberately
 * absent and no column here can express it. An offer earned by joining would
 * break hard rule 1 (joining is free of cost) and hard rule 2 (value only from
 * product sales) — so every grant in this schema hangs off BV the distributor
 * personally purchased, and there is nowhere to record any other basis.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Which product carries the half-price offer in a given month. One per
        // month: "the company's monthly-announced product" is singular, and a
        // unique index is how that stays true.
        Schema::create('monthly_offer_products', function (Blueprint $table): void {
            $table->id();
            $table->date('month_start')->unique('uniq_monthly_offer_month');
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_offer_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distributor_id')->constrained('distributors')->cascadeOnDelete();
            $table->enum('offer_type', ['half_price_product', 'redeem_points']);
            $table->date('month_start');

            // What the distributor did to earn it. Stored rather than
            // recomputed: hard rule 2 requires every grant to trace to product
            // sales, and a figure nobody can decompose is not a trace.
            $table->bigInteger('qualifying_bv_paise')->default(0);
            $table->unsignedTinyInteger('streak_months')->default(0);

            // Half-price grants name the variant; points grants name the award.
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('points_awarded')->default(0);

            $table->enum('status', ['granted', 'consumed', 'expired'])->default('granted');
            $table->date('expires_on')->nullable();
            $table->foreignId('consumed_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->dateTime('consumed_at', 3)->nullable();

            $table->timestamps();

            $table->unique(['distributor_id', 'offer_type', 'month_start'], 'uniq_offer_grant');
            $table->index(['offer_type', 'status'], 'idx_offer_grants_type_status');
        });

        // Redeem points are NOT money. They are a discount entitlement earned
        // from a distributor's own purchases, so they live in their own
        // append-only ledger and never touch the wallet — which holds cash the
        // company owes and which is subject to TDS, admin charge and payout.
        Schema::create('redeem_point_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distributor_id')->constrained('distributors')->cascadeOnDelete();
            // Signed: positive accrual, negative redemption or expiry.
            $table->integer('points');
            $table->enum('type', ['accrual', 'redemption', 'expiry', 'adjustment']);
            $table->string('reference_type', 48)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('memo', 255)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['distributor_id', 'created_at'], 'idx_redeem_points_dist_time');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Kept apart from `discount_paise`, which belongs to coupons. Two
            // reductions with different rules and different records should not
            // share a column, or neither can be reconciled afterwards.
            $table->bigInteger('redeem_points_paise')->default(0)->after('discount_paise');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('redeem_points_paise');
        });

        Schema::dropIfExists('redeem_point_entries');
        Schema::dropIfExists('purchase_offer_grants');
        Schema::dropIfExists('monthly_offer_products');
    }
};
