<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client 2026-10-09 Lifetime Awards & Rewards: awards are merchandise only and
 * no payout ever carries an award, so the admin-charge toggle for awards
 * (comp.admin_charge.applies_to_awards) has nothing to switch. The key is gone
 * from the registry, the seeder and the plan defaults; this removes the row.
 *
 * The audit row records the value it held (`deleted: false` when the row was
 * already absent).
 *
 * Fail-safe: the same release stops the monthly payout from sweeping the
 * retired `awards_credit` ledger type. An unswept row of that type would be
 * stranded — never paid, never surfaced — so the migration counts them first
 * and refuses while one exists; the counts (total and unswept) go on the audit
 * row either way. The ledger `type` enum itself is not narrowed here.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.remove_admin_charge_applies_to_awards_setting';

    private const KEY = 'comp.admin_charge.applies_to_awards';

    public function up(): void
    {
        $awardsCredits = DB::table('wallet_ledger_entries')->where('type', 'awards_credit')->count();
        $unswept = DB::table('wallet_ledger_entries')
            ->where('type', 'awards_credit')
            ->whereNull('swept_by_payout_batch_id')
            ->count();

        if ($unswept > 0) {
            throw new RuntimeException("Refusing to retire the awards cash path: {$unswept} unswept awards_credit wallet ledger row(s) would be stranded (never swept by a monthly payout again). Sweep or reverse them deliberately first.");
        }

        DB::transaction(function () use ($awardsCredits): void {
            $before = DB::table('settings')->where('key', self::KEY)->value('value');
            $deleted = DB::table('settings')->where('key', self::KEY)->delete() > 0;

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'setting',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_09_101100_remove_admin_charge_applies_to_awards_setting',
                    'reason' => 'Client 2026-10-09: Lifetime Awards are merchandise only; the awards admin-charge toggle has no stream to apply to.',
                    'key' => self::KEY,
                    'value_before' => $before !== null ? (string) $before : null,
                    'deleted' => $deleted,
                    'awards_credit_ledger_rows' => $awardsCredits,
                    'awards_credit_unswept' => 0,
                ],
            ]);
        });
    }

    public function down(): void
    {
        // Never guess: the deleted value is only known from the audit row.
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore from the plan.migration.* audit row '
            .'(value_before holds the deleted value).'
        );
    }
};
