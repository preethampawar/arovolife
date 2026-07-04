<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Adds the 'no_bank_account' line status: the distributor cleared every income
// gate but has no bank account on file, so the payout is held in the wallet —
// never debited, never swept — until bank details arrive. This is the
// payout-side enforcement of the registration promise "we cannot release any
// commission payout until your bank account is on file".
return new class extends Migration
{
    private const NEW = "ENUM('pending','transferred','failed','below_minimum','web_only','no_bank_account')";

    private const OLD = "ENUM('pending','transferred','failed','below_minimum','web_only')";

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payout_line_items MODIFY status '.self::NEW." DEFAULT 'pending'");

            return;
        }

        // SQLite (tests): relax to a plain string — the native column change
        // rebuilds the table, dropping the enum's CHECK constraint. Valid
        // values are enforced by the PayoutLineItem STATUS_* constants.
        Schema::table('payout_line_items', function (Blueprint $table): void {
            $table->string('status')->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payout_line_items MODIFY status '.self::OLD." DEFAULT 'pending'");
        }
    }
};
