<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal-BV ledger (ADR-0006): an append-only projection of confirmed
 * product sales. Each row references an order (hard rule #2 — no BV without a
 * sale). Accrual entries are written when an order's cooling-off expires;
 * reversal entries when it is refunded. Total personal BV = SUM(bv_paise).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: skip if already created (self-heals a partially-migrated DB).
        if (Schema::hasTable('bv_ledger_entries')) {
            return;
        }

        Schema::create('bv_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distributor_id')->constrained('distributors')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->bigInteger('bv_paise'); // signed: + accrual, − reversal
            $table->enum('type', ['accrual', 'reversal']);
            $table->dateTime('effective_at', 3);
            $table->timestamps();

            // One accrual and one reversal per order — the idempotency guard.
            $table->unique(['order_id', 'type']);
            $table->index('distributor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bv_ledger_entries');
    }
};
