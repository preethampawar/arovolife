<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring back the 'awards_credit' wallet ledger type. Lifetime Awards are paid
 * in cash with the monthly payout on the 8th (the client, 2026-11-10), which
 * reverses 2026_10_09_101300: each award is one awards_credit row referencing
 * its lifetime_award_milestones row.
 *
 * MySQL only. The list is the one 2026_10_09_101300::down() restores (the
 * 2026_09_05_120000 list, awards_credit included). SQLite keeps the CREATE-time
 * CHECK list, as every widening of this column does.
 *
 * down() refuses while a single awards_credit row exists — narrowing the ENUM
 * under it would rewrite or reject a paid award.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.add_awards_credit_wallet_type';

    private const ADDED = 'awards_credit';

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','admin_charge_debit','tds_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used','repurchase_transfer') NOT NULL");
        }

        // `migrate --pretend` only prints SQL; the audit row's binary hash
        // cannot be printed.
        if (DB::pretending()) {
            return;
        }

        // Through the model, not a raw insert: the creating hook links the
        // row into the audit hash chain, which a raw insert would skip.
        AuditLog::create([
            'actor_id' => null,
            'action' => self::ACTION,
            'subject_type' => 'compensation_status_enum',
            'subject_id' => null,
            'details' => [
                'migration' => '2026_11_11_100000_add_awards_credit_type_to_wallet_ledger_entries',
                'reason' => 'Lifetime Awards are paid in cash with the monthly payout (the client, 2026-11-10); each award is one awards_credit row.',
                'driver' => DB::getDriverName(),
                'wallet_ledger_entries' => ['column' => 'type', 'added' => [self::ADDED]],
            ],
        ]);
    }

    public function down(): void
    {
        $carrying = DB::table('wallet_ledger_entries')->where('type', self::ADDED)->count();

        if ($carrying > 0) {
            throw new RuntimeException("Refusing to narrow the wallet ledger type enum: {$carrying} wallet_ledger_entries rows carry awards_credit.");
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','payout_debit','admin_charge_debit','tds_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal','repurchase_wallet_used','repurchase_transfer') NOT NULL");
    }
};
