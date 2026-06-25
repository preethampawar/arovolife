<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->enum('type', [
                'gsb_credit',
                'mb_credit',
                'gbb_credit',
                'payout_debit',
                'repurchase_deduction',
                'manual_credit',
                'reversal',
            ]);
            $table->bigInteger('amount_paise');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->text('memo')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['distributor_id', 'created_at'], 'idx_wallet_dist_created');
            $table->index(['reference_type', 'reference_id'], 'idx_wallet_ref');
            $table->foreign('distributor_id', 'fk_wallet_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
    }
};
