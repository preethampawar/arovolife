<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            // Covers the payout sweep queries:
            //   WHERE type IN (...) AND swept_by_payout_batch_id IS NULL [AND distributor_id = ?]
            // The ledger is append-only and grows by one row per distributor per
            // credited cut-off, so an uncovered scan degrades weekly.
            $table->index(
                ['type', 'swept_by_payout_batch_id', 'distributor_id'],
                'idx_wallet_sweep_scan',
            );
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('idx_wallet_sweep_scan');
        });
    }
};
