<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Two ledger entry types the payout pipeline needs that the MySQL ENUM
        // never received:
        //  - 'awards_credit': Group C (Lifetime Awards cash component) — swept
        //    by the monthly batch but uncreditable until the ENUM allows it.
        //  - 'rank_cap_forfeit': debit posted for rank credits above the
        //    monthly income cap, so the swept-but-uncapped excess does not
        //    linger as a phantom positive wallet balance.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','manual_credit','reversal') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','payout_debit','repurchase_deduction','manual_credit','reversal') NOT NULL");
        }
    }
};
