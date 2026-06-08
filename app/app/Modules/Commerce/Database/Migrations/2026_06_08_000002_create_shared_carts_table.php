<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-product "Easy Purchase" share links. A distributor snapshots their
 * current cart into a SharedCart; the recipient opens /shop/easy-cart/{code},
 * which loads the items into their own cart and credits the sharer (same
 * 30-day attribution as the single-product ?ref link). Items are a snapshot,
 * not a live cart — re-priced from the variant when added to the recipient's
 * cart, so no stale price is ever carried over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_carts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            // The sharing distributor (for attribution). Nullable + no FK so a
            // later distributor purge never blocks reading an old share link.
            $table->unsignedBigInteger('distributor_id')->nullable()->index();
            $table->string('ref_adn', 16)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            // [{ "variant_id": int, "qty": int }, ...]
            $table->json('items');
            $table->dateTime('expires_at', 3);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_carts');
    }
};
