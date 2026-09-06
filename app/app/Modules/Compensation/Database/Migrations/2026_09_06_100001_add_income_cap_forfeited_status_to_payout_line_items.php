<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds the 'income_cap_forfeited' line status: the distributor's whole balance
// for this batch sat above the combined ₹50L monthly income cap of the month it
// was earned for, so the credits were swept and written off with an
// income_cap_forfeit ledger debit. Previously the batch simply exited without a
// row, which made a forfeit indistinguishable from a distributor who was never
// looked at. SQLite stores status as a plain string (see the no_bank_account
// migration), so only MySQL needs the enum widened.
return new class extends Migration
{
    private const NEW = "ENUM('pending','transferred','failed','below_minimum','web_only','no_bank_account','kyc_pending','bank_decrypt_failed','income_cap_forfeited')";

    private const OLD = "ENUM('pending','transferred','failed','below_minimum','web_only','no_bank_account','kyc_pending','bank_decrypt_failed')";

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payout_line_items MODIFY status '.self::NEW." DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Re-map before narrowing: MySQL truncates (or rejects) rows whose
            // value is no longer in the ENUM, which would erase the record of
            // income that was written off — a DSR r.5 record-keeping obligation.
            DB::table('payout_line_items')
                ->where('status', 'income_cap_forfeited')
                ->update(['status' => 'below_minimum']);

            DB::statement('ALTER TABLE payout_line_items MODIFY status '.self::OLD." DEFAULT 'pending'");
        }
    }
};
