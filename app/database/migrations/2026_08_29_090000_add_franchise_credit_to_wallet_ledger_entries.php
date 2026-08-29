<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `FranchiseCommissionService` writes wallet entries of type `franchise_credit`,
 * and the enum did carry it: `2026_08_16_130100_create_franchise_commission_results_table`
 * widened the column when the engine landed. `2026_08_28_200000_add_repurchase_wallet_used_to_wallet_ledger_entries`
 * then dropped it again — it rewrote the whole enum from a hand-copied list that
 * predated the franchise migration. This is a regression from that commit, not
 * an original gap. On MySQL the insert now fails with "1265 Data truncated for
 * column 'type'", so a franchise commission run credits nothing.
 *
 * The root cause is structural, and it will recur: every type migration restates
 * the entire enum by hand, so any one of them can silently drop a value another
 * added. Two things hid it. The enum is only enforced on MySQL — sqlite takes any
 * string. And CommissionHasProductSaleTest's enum check asserts only that every
 * DECLARED value is classified, never the reverse, so a classified value missing
 * from the enum is invisible to it by construction.
 *
 * Nothing is live yet — franchise is parked for Phase 4, so no `franchise_credit`
 * row existed to be blanked by the narrowing. Verify that on staging before
 * deploying rather than assuming it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','franchise_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used') NOT NULL");
        }
    }
};
