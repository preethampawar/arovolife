<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds the 'bank_decrypt_failed' line status (LOG-2): the distributor has a
// bank account on file but its ciphertext no longer decrypts, so the payout
// is held in the wallet — never debited, never swept — until the details are
// re-captured. Without this status the line went into the NEFT file with a
// blank account number. SQLite stores status as a plain string (see the
// no_bank_account migration), so only MySQL needs the enum widened.
return new class extends Migration
{
    private const NEW = "ENUM('pending','transferred','failed','below_minimum','web_only','no_bank_account','kyc_pending','bank_decrypt_failed')";

    private const OLD = "ENUM('pending','transferred','failed','below_minimum','web_only','no_bank_account','kyc_pending')";

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
            // value is no longer in the ENUM, which would erase the record of a
            // payout that was withheld — a DSR r.5 record-keeping obligation.
            DB::table('payout_line_items')
                ->where('status', 'bank_decrypt_failed')
                ->update(['status' => 'failed']);

            DB::statement('ALTER TABLE payout_line_items MODIFY status '.self::OLD." DEFAULT 'pending'");
        }
    }
};
