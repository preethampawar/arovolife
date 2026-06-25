<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payout_batch_id');
            $table->unsignedBigInteger('distributor_id');
            $table->bigInteger('wallet_balance_paise');
            $table->bigInteger('repurchase_deduction_paise')->default(0);
            $table->bigInteger('net_transferred_paise');
            $table->string('bank_account_last4', 4)->nullable();
            $table->string('utr_number', 64)->nullable();
            $table->enum('status', ['pending', 'transferred', 'failed', 'below_minimum'])->default('pending');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index('payout_batch_id', 'idx_payout_line_batch');
            $table->index('distributor_id', 'idx_payout_line_dist');
            $table->unique(['payout_batch_id', 'distributor_id'], 'uniq_payout_line');
            $table->foreign('payout_batch_id', 'fk_payout_line_batch')
                ->references('id')->on('payout_batches')->cascadeOnDelete();
            $table->foreign('distributor_id', 'fk_payout_line_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_line_items');
    }
};
