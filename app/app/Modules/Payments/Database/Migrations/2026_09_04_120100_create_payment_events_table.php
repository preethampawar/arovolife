<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every interaction with a payment gateway, in one place: outbound API
 * calls, browser callbacks, webhooks. `payload` is the allow-listed
 * (scrubbed) object only — there is no raw copy anywhere. The unique
 * `(gateway, gateway_event_id)` pair is what makes webhook delivery
 * idempotent: Razorpay retries and re-orders, we apply each event once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_intent_id')->nullable()->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('refund_intent_id')->nullable()->constrained('refund_intents')->restrictOnDelete();
            $table->string('gateway', 16);
            $table->string('direction', 16); // outbound | callback | webhook | system
            $table->string('event_type', 64);
            $table->string('gateway_event_id', 64)->nullable();
            $table->string('gateway_payment_id', 64)->nullable();
            $table->boolean('signature_verified')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            // Webhooks only: when the queued handler applied the event, or why it could not.
            $table->dateTime('processed_at', 3)->nullable();
            $table->text('processing_error')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->unique(['gateway', 'gateway_event_id'], 'uniq_payment_events_gateway_event');
            $table->index(['order_id', 'created_at'], 'idx_payment_events_order');
            $table->index('payment_intent_id', 'idx_payment_events_intent');
            $table->index('gateway_payment_id', 'idx_payment_events_gateway_payment');
            $table->index('created_at', 'idx_payment_events_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
