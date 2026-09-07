<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The DAY a wallet entry was earned, as opposed to the moment it was written.
     *
     * The weekly payout pays a Wednesday→Tuesday earning week one Tuesday after
     * it closes (client 2026-09-07), and the week is keyed on the day the income
     * was earned — the GSB cut-off date, the mentorship cut-off day — not on
     * `created_at`. Tuesday's cut-off is credited at 00:10 on Wednesday, so a
     * window read off the write timestamp would push every Tuesday's income into
     * the following week and pay it seven days late, for ever.
     *
     * `bonus_month` cannot answer this: it is the earned MONTH, and four
     * different earning weeks share one month.
     *
     * Nullable because no row written before this migration recorded the fact,
     * and because the monthly (Group B/C/D) streams have no earning day at all —
     * they are earned for a month and paid on the 8th. A null row is swept by the
     * first batch that sees it, exactly as it was before the week existed.
     */
    public function up(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table): void {
            $table->date('earned_on')->nullable()->after('bonus_month');

            // The weekly batch's shape, in order: the Group A types, still
            // unswept, earned on or before the end of the week being paid.
            $table->index(['type', 'swept_by_payout_batch_id', 'earned_on'], 'idx_wallet_type_swept_earned');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('idx_wallet_type_swept_earned');
            $table->dropColumn('earned_on');
        });
    }
};
