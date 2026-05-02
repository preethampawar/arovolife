<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique('uniq_ledger_accounts_code');
            $table->string('name', 150);
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->char('currency', 3)->default('INR');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->foreign('parent_id', 'fk_ledger_accounts_parent')
                ->references('id')->on('ledger_accounts')->nullOnDelete();
            $table->index('type', 'idx_ledger_accounts_type');
        });

        Schema::create('ledger_tx', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('occurred_at', 3);
            $table->string('source_module', 32);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 128)->unique('uniq_ledger_tx_idempotency');
            $table->string('memo', 500)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->foreign('created_by_user_id', 'fk_ledger_tx_user')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['source_module', 'source_type', 'source_id'], 'idx_ledger_tx_source');
            $table->index('occurred_at', 'idx_ledger_tx_occurred_at');
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_tx_id')->constrained('ledger_tx')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->enum('side', ['debit', 'credit']);
            $table->bigInteger('amount_paise');
            $table->char('currency', 3)->default('INR');
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['account_id', 'side'], 'idx_ledger_entries_account_side');
            $table->index('ledger_tx_id', 'idx_ledger_entries_tx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_tx');
        Schema::dropIfExists('ledger_accounts');
    }
};
