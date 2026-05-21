<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make bank account + IFSC nullable on `distributors`. Bank details are
 * now optional at registration — they're only required before the first
 * payout (Phase 2+). DSR 2021 does not mandate bank capture at signup,
 * only that commissions land in the distributor's bank account when
 * paid. Distributors can add or update bank details later from their
 * dashboard.
 */
return new class extends Migration {
    public function up(): void
    {
        // MySQL — the original migration explicitly ran a NOT NULL
        // ALTER on bank_account_enc, so we need to reverse that AND
        // drop the implicit NOT NULL on bank_ifsc (which was `char(11)`
        // without ->nullable()).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE distributors MODIFY bank_account_enc VARBINARY(512) NULL');
            DB::statement('ALTER TABLE distributors MODIFY bank_ifsc CHAR(11) NULL');

            return;
        }

        // SQLite (test :memory: DB) — column ALTERs are limited, but
        // the test suite seeds rows via the model/service layer which
        // we're updating to write NULL, so on SQLite a NULL insert
        // into a NOT NULL column is the failure we want to remove.
        // Use Schema::change() (Laravel 11+ native) which rebuilds
        // the table with the updated column definitions.
        Schema::table('distributors', function ($table): void {
            $table->binary('bank_account_enc')->nullable()->change();
            $table->string('bank_ifsc', 11)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Backfill any NULLs to the sentinels the old code used so
            // the NOT NULL re-enforcement does not error.
            DB::table('distributors')->whereNull('bank_account_enc')->update(['bank_account_enc' => '']);
            DB::table('distributors')->whereNull('bank_ifsc')->update(['bank_ifsc' => 'ARVO0000000']);

            DB::statement('ALTER TABLE distributors MODIFY bank_account_enc VARBINARY(512) NOT NULL');
            DB::statement('ALTER TABLE distributors MODIFY bank_ifsc CHAR(11) NOT NULL');

            return;
        }

        Schema::table('distributors', function ($table): void {
            $table->binary('bank_account_enc')->nullable(false)->change();
            $table->string('bank_ifsc', 11)->nullable(false)->change();
        });
    }
};
