<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0009 build step 2:
 * Add liability.refund_payable to the chart of accounts.
 *
 * This account is credited when a refund is approved (ledger move) and debited
 * when Phase-3 settles the actual gateway refund. It is distinct from
 * liability.customer_prepayment (which is zeroed out when revenue is recognised
 * on ship) — using a separate account makes it clear the liability is "refund
 * owed" not "product not yet delivered".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ledger_accounts')->updateOrInsert(
            ['code' => 'liability.refund_payable'],
            [
                'code' => 'liability.refund_payable',
                'name' => 'Refund payable (approved, pending gateway settlement)',
                'type' => 'liability',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('ledger_accounts')->where('code', 'liability.refund_payable')->delete();
    }
};
