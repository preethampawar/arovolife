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
            // Tracks which payout batch swept this credit entry.
            // NULL = not yet swept. Payout batches query WHERE swept_by_payout_batch_id IS NULL
            // instead of a date window, ensuring below-minimum carryover entries
            // from prior cycles are always picked up in the next run.
            $table->unsignedBigInteger('swept_by_payout_batch_id')->nullable()->after('memo');

            $table->index('swept_by_payout_batch_id', 'idx_wallet_swept_batch');
            $table->foreign('swept_by_payout_batch_id', 'fk_wallet_swept_batch')
                ->references('id')->on('payout_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->dropForeign('fk_wallet_swept_batch');
            $table->dropIndex('idx_wallet_swept_batch');
            $table->dropColumn('swept_by_payout_batch_id');
        });
    }
};
