<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds the 'admin_charge_debit' and 'tds_debit' ledger types. The payout used
// to leave the wallet as a single `payout_debit` for the whole post-repurchase
// balance, which meant the ledger could not answer "where did the rest of my
// money go?" — the admin charge and the TDS were only ever recorded on the
// payout line item. Splitting them into their own debits keeps the wallet
// closing to exactly zero (admin + TDS + net == the same total) while making
// each statutory deduction a first-class, auditable ledger fact.
// SQLite (tests) stores the column as a string, so no DDL is needed there.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','admin_charge_debit','tds_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used','repurchase_transfer') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used','repurchase_transfer') NOT NULL");
        }
    }
};
