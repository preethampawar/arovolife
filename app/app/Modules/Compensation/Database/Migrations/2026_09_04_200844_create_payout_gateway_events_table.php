<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "What did we ask the payout gateway, and what did it say?" — answerable
 * from the database, the same contract `payment_events` gives the inbound
 * side.
 *
 * Money leaving the company needs a stronger trail than money arriving: a
 * disputed transfer is answered from these rows plus the audit log. Payloads
 * are written through RazorpayPayoutPayloadScrubber, so no bank account
 * number, IFSC or name is ever stored here (hard rule 8; DPDP §4).
 *
 * The unique (gateway, gateway_event_id) index is what makes a redelivered
 * webhook a no-op instead of a second status change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_gateway_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payout_line_item_id')->nullable();
            $table->unsignedBigInteger('payout_batch_id')->nullable();
            $table->string('gateway', 16)->default('razorpayx');
            $table->string('direction', 16);
            $table->string('event_type', 64);
            $table->string('gateway_event_id', 64)->nullable();
            $table->string('gateway_payout_id', 50)->nullable();
            $table->boolean('signature_verified')->default(false);
            $table->smallInteger('http_status')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('processed_at', 3)->nullable();
            $table->text('processing_error')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index('payout_line_item_id', 'idx_payout_event_line');
            $table->index('payout_batch_id', 'idx_payout_event_batch');
            $table->index('gateway_payout_id', 'idx_payout_event_gateway_payout');
            $table->unique(['gateway', 'gateway_event_id'], 'uniq_payout_event_gateway_event');

            $table->foreign('payout_line_item_id', 'fk_payout_event_line')
                ->references('id')->on('payout_line_items')->nullOnDelete();
            $table->foreign('payout_batch_id', 'fk_payout_event_batch')
                ->references('id')->on('payout_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_gateway_events');
    }
};
