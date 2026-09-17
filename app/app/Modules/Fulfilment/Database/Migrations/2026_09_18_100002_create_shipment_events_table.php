<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every interaction with a courier gateway, in one place: outbound API calls
 * and inbound tracking webhooks. Deliberately the same shape as
 * `payment_events` (2026_09_04_120100) — that table's contract has held
 * through a real integration and there is no reason to invent a second one.
 *
 * `payload` is the allow-listed (scrubbed) object only; there is no raw copy
 * anywhere, because a courier payload carries the buyer's name, phone and full
 * delivery address. The unique `(gateway, gateway_event_id)` pair is what makes
 * webhook delivery idempotent: couriers retry and re-order, we apply each event
 * once.
 *
 * A collection code never appears here. It is issued and verified through
 * `Shared\Otp\OtpService` and is not part of any courier exchange.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('gateway', 16);
            $table->string('direction', 16); // outbound | webhook | system
            $table->string('event_type', 64);
            $table->string('gateway_event_id', 64)->nullable();
            $table->string('gateway_shipment_id', 64)->nullable();
            $table->boolean('signature_verified')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            // Webhooks only: when the queued handler applied the event, or why it could not.
            $table->dateTime('processed_at', 3)->nullable();
            $table->text('processing_error')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->unique(['gateway', 'gateway_event_id'], 'uniq_shipment_events_gateway_event');
            $table->index(['order_id', 'created_at'], 'idx_shipment_events_order');
            $table->index('shipment_id', 'idx_shipment_events_shipment');
            $table->index('gateway_shipment_id', 'idx_shipment_events_gateway_shipment');
            $table->index('created_at', 'idx_shipment_events_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
    }
};
