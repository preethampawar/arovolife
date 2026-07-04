<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds the 'income_cap_forfeit' ledger type. KP's 2026-06-26 plan caps the
// combined monthly gross of the five cash bonuses (GSB, MB, GBB, Rank,
// Fortune) at ₹50L; income above the cap is forfeited at payout time with an
// explicit debit so no phantom balance lingers. 'rank_cap_forfeit' remains in
// the ENUM for historical rows written while the cap was rank-only.
// SQLite (tests) stores the column as a string, so no DDL is needed there.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','manual_credit','reversal') NOT NULL");
        }
    }
};
