<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Razorpay facts on the intent. All additive, no enum changes (the status
 * enum is left as is — "expired" is `cancelled` + `cancel_reason`, and a
 * dismissed attempt is a `payment_events` row, not an intent status).
 * `mode` records whether the money was real (`live`) or not (`test`) so an
 * auditor can tell from the row alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->string('gateway_order_id', 64)->nullable()->after('gateway_intent_id');
            $table->string('gateway_payment_id', 64)->nullable()->after('gateway_order_id');
            $table->string('mode', 8)->nullable()->after('gateway_payment_id');
            $table->string('method', 32)->nullable()->after('mode');
            $table->string('error_code', 64)->nullable()->after('method');
            $table->string('error_description', 255)->nullable()->after('error_code');
            $table->string('cancel_reason', 32)->nullable()->after('error_description');
            $table->string('confirmed_via', 16)->nullable()->after('cancel_reason');
            $table->unsignedInteger('attempt_count')->default(0)->after('confirmed_via');
            $table->dateTime('authorised_at', 3)->nullable()->after('attempt_count');
            $table->dateTime('expires_at', 3)->nullable()->after('authorised_at');
            $table->dateTime('last_synced_at', 3)->nullable()->after('expires_at');
            $table->dateTime('signature_verified_at', 3)->nullable()->after('last_synced_at');

            $table->index('gateway_order_id', 'idx_payment_intents_gateway_order');
            $table->index('gateway_payment_id', 'idx_payment_intents_gateway_payment');
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropIndex('idx_payment_intents_gateway_order');
            $table->dropIndex('idx_payment_intents_gateway_payment');
            $table->dropColumn([
                'gateway_order_id', 'gateway_payment_id', 'mode', 'method', 'error_code',
                'error_description', 'cancel_reason', 'confirmed_via', 'attempt_count',
                'authorised_at', 'expires_at', 'last_synced_at', 'signature_verified_at',
            ]);
        });
    }
};
