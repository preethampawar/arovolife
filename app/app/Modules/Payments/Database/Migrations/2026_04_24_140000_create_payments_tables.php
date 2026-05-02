<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->enum('gateway', ['razorpay', 'payu', 'stub'])->default('stub');
            $table->string('gateway_intent_id', 128)->nullable();
            $table->bigInteger('amount_paise');
            $table->enum('status', ['created', 'authorised', 'captured', 'failed', 'cancelled'])->default('created');
            $table->string('idempotency_key', 128)->unique('uniq_payment_intents_idempotency');
            $table->json('raw_payload')->nullable();
            $table->dateTime('captured_at', 3)->nullable();
            $table->dateTime('failed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index('order_id', 'idx_payment_intents_order');
        });

        Schema::create('refund_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_intent_id')->nullable()->constrained('payment_intents')->nullOnDelete();
            $table->enum('gateway', ['razorpay', 'payu', 'stub'])->default('stub');
            $table->string('gateway_refund_id', 128)->nullable();
            $table->bigInteger('amount_paise');
            $table->enum('status', ['created', 'processed', 'failed'])->default('created');
            $table->string('reason_code', 32);
            $table->string('idempotency_key', 128)->unique('uniq_refund_intents_idempotency');
            $table->dateTime('processed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_intents');
        Schema::dropIfExists('payment_intents');
    }
};
