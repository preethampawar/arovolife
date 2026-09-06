<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The calendar month a wallet entry was EARNED for, as opposed to the month
     * it happened to be written in.
     *
     * Every monthly engine runs in the small hours of the 1st for the month that
     * just closed, so August's Rank, Growth Booster, Fortune and ADC credits are
     * all written on 1 September. Windowing the per-distributor monthly ceilings
     * on `created_at` therefore billed August's income against September's
     * ceiling, and made four engines compete for it in cron order — the one that
     * ran first took the room, the ones behind it took less than they were owed,
     * and September's own income then found the ceiling already spent.
     *
     * `bonus_month` is the first day of the earned IST month. It is nullable
     * because every row written before this migration has no such fact to
     * record: those rows keep answering under the `created_at` fallback in
     * {@see WalletService::repurchaseDeductionForMonthPaise()}.
     */
    public function up(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table): void {
            $table->date('bonus_month')->nullable()->after('reference_type');

            // Both ceilings read the same shape: one distributor, a set of bonus
            // types, one earned month — the repurchase deduction cap here, and
            // the ₹50L combined monthly income cap that consumes this column.
            $table->index(['distributor_id', 'type', 'bonus_month'], 'idx_wallet_dist_type_bonus_month');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('idx_wallet_dist_type_bonus_month');
            $table->dropColumn('bonus_month');
        });
    }
};
