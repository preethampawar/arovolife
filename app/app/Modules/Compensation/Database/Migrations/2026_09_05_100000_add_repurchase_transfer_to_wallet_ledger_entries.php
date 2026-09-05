<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds the 'repurchase_transfer' ledger type: the DEBIT side of the
// credit-time repurchase deduction. When a bonus is credited, the gross lands
// in the main wallet, this entry takes the deduction back out of it, and a
// matching 'repurchase_deduction' credit puts the same amount in the
// repurchase wallet. Deliberately NOT part of WalletService::REPURCHASE_TYPES —
// it has to be counted in the main wallet balance, as a negative, or the main
// balance would never fall from the gross to the net. SQLite (tests) stores the
// column as a string, so no DDL is needed there.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used','repurchase_transfer') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used') NOT NULL");
        }
    }
};
