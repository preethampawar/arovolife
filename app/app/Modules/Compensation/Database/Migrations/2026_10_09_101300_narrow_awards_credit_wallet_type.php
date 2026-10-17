<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire the 'awards_credit' wallet ledger type. Lifetime Awards are
 * merchandise only (the client, 2026-10-09): 2026_10_09_101000 dropped the
 * award cash columns and 2026_10_09_101100 retired the awards admin-charge
 * setting, so nothing writes this type and no payout group sweeps it.
 *
 * Fail-safe: count first and refuse before any DDL while a single row still
 * carries the type — narrowing a MySQL ENUM under such a row would rewrite or
 * reject it.
 *
 * MySQL only. The list is the one 2026_09_05_120000 (the latest widening of
 * this column) wrote, minus 'awards_credit'. SQLite has nothing to narrow:
 * the column was created with Blueprint::enum() (2026_06_24_100005), so it
 * carries the CREATE-time CHECK list, and no widening ever ran DDL there —
 * 'awards_credit' was never in that CHECK. The refusal runs on every driver.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.narrow_awards_credit_wallet_type';

    private const REMOVED = 'awards_credit';

    public function up(): void
    {
        $carrying = DB::table('wallet_ledger_entries')->where('type', self::REMOVED)->count();

        if ($carrying > 0) {
            throw new RuntimeException("Refusing to narrow the wallet ledger type enum: {$carrying} wallet_ledger_entries rows still carry awards_credit. Replay or wipe history first.");
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','payout_debit','admin_charge_debit','tds_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used','repurchase_transfer') NOT NULL");
        }

        // Through the model, not a raw insert: the creating hook links the
        // row into the audit hash chain, which a raw insert would skip.
        AuditLog::create([
            'actor_id' => null,
            'action' => self::ACTION,
            'subject_type' => 'compensation_status_enum',
            'subject_id' => null,
            'details' => [
                'migration' => '2026_10_09_101300_narrow_awards_credit_wallet_type',
                'reason' => 'Lifetime Awards are merchandise only (the client, 2026-10-09); nothing writes the awards_credit ledger type.',
                'driver' => DB::getDriverName(),
                'wallet_ledger_entries' => ['column' => 'type', 'removed' => [self::REMOVED], 'rows_carrying' => $carrying],
            ],
        ]);
    }

    public function down(): void
    {
        // Schema only: the enum widens back to the 2026_09_05_120000 list.
        // No row moves.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','admin_charge_debit','tds_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used','repurchase_transfer') NOT NULL");
    }
};
