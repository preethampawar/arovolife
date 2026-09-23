<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment behind an offline order, as staff recorded it and finance
 * confirmed (or rejected) it. One row per order.
 *
 * Nothing here is money on the books: the ledger entry is posted only when
 * finance confirms (PaymentConfirmationService::confirmOffline()). Channel and
 * status are strings rather than enums so a new channel never needs the
 * MySQL/SQLite enum-widen dance.
 *
 * The proof file is PiiCrypter ciphertext on the `offline-payments` disk
 * (deposit slips carry account numbers, and a cash-deposit slip of ₹50,000 or
 * more carries a PAN); `proof_purged_at` records the retention sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('distributor_id')->constrained('distributors');
            $table->string('channel', 24);
            $table->string('channel_other', 60)->nullable();
            $table->unsignedBigInteger('amount_paise');
            $table->date('received_on');
            $table->string('reference_no', 64)->nullable();
            $table->string('payer_name', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('proof_storage_key', 255)->nullable();
            $table->string('proof_original_name', 255)->nullable();
            $table->string('proof_mime', 64)->nullable();
            $table->unsignedInteger('proof_size_bytes')->nullable();
            $table->char('proof_sha256', 64)->nullable();
            $table->timestamp('proof_purged_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('terms_acknowledged_at');
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmation_note', 500)->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['channel', 'reference_no'], 'idx_offline_payments_channel_ref');
            $table->index(['distributor_id', 'channel', 'received_on'], 'idx_offline_payments_cash_day');
            $table->index('status', 'idx_offline_payments_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_payments');
    }
};
