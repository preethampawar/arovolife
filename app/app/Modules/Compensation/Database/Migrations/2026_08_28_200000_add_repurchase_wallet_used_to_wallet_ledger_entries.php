<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds the 'repurchase_wallet_used' ledger type so the repurchase wallet
// balance can be spent at Commerce checkout. This is a debit entry (negative
// amount_paise) recorded when the auto-applied repurchase credit reduces an
// order's payable total. SQLite (tests) stores the column as a string, so no
// DDL is needed there.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal') NOT NULL");
        }
    }
};
