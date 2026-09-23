<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cash taken at the office counter for an offline order. Kept apart from the
 * settlement bank account so the cash drawer can be reconciled on its own:
 * money deposited at the bank or paid by UPI/NEFT lands in
 * `asset.cash.bank.settlement` as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ledger_accounts')->updateOrInsert(
            ['code' => 'asset.cash.office'],
            [
                'code' => 'asset.cash.office',
                'name' => 'Cash at office (collected at reception)',
                'type' => 'asset',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $account = DB::table('ledger_accounts')->where('code', 'asset.cash.office')->first();
        if ($account === null) {
            return;
        }

        if (DB::table('ledger_entries')->where('account_id', $account->id)->exists()) {
            throw new RuntimeException('asset.cash.office has ledger entries; it cannot be removed.');
        }

        DB::table('ledger_accounts')->where('id', $account->id)->delete();
    }
};
