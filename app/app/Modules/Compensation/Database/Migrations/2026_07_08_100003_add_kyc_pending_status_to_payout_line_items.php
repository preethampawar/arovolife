<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds the 'kyc_pending' line status: the distributor cleared every income gate
// but their KYC is not yet verified (users.status !== 'active'), so the payout
// is held in the wallet — never debited, never swept — until KYC is approved.
// Distributors still see and accrue income; only the bank release is gated
// (partner instruction 2026-07-08). SQLite already stores status as a plain
// string (see the no_bank_account migration), so only MySQL needs the enum.
return new class extends Migration
{
    private const NEW = "ENUM('pending','transferred','failed','below_minimum','web_only','no_bank_account','kyc_pending')";

    private const OLD = "ENUM('pending','transferred','failed','below_minimum','web_only','no_bank_account')";

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payout_line_items MODIFY status '.self::NEW." DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payout_line_items MODIFY status '.self::OLD." DEFAULT 'pending'");
        }
    }
};
