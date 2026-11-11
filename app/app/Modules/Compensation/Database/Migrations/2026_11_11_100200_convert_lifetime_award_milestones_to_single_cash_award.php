<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire the merchandise tranche model (the client, 2026-11-10). Lifetime
 * Awards are now one cash award per rank, paid with the monthly payout, and
 * only for qualification months on or after comp.awards.cash_effective_month
 * — there is no retrospective payment (user decision 2026-11-11, Q3).
 *
 * So every milestone still `pending` was earned under the merchandise plan,
 * before the cash effective month, and is cancelled with a note: a tranche B/C
 * because the tranche model is gone, a tranche A because earlier months are
 * not paid in cash. A cancelled milestone keeps blocking the same rank, so a
 * later re-achievement pays nothing (once per lifetime). Delivered milestones
 * are never touched — the merchandise was handed over.
 *
 * Every row gets achieved_month = triggered_month. The audit row lists every
 * cancelled row (id, rank, tranche, amount) when under 500.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.convert_lifetime_award_milestones_to_single_cash_award';

    private const NOTE_TRANCHE = 'Tranche model retired 2026-11-10: a Lifetime Award is one cash amount per rank.';

    private const NOTE_MERCHANDISE = 'Merchandise award retired 2026-11-10: cash awards are paid only for months from the cash effective month, never retrospectively.';

    public function up(): void
    {
        if (DB::pretending()) {
            DB::table('lifetime_award_milestones')->whereNull('achieved_month')->update(['achieved_month' => DB::raw('triggered_month')]);
            DB::table('lifetime_award_milestones')->where('status', 'pending')->update(['status' => 'cancelled']);

            return;
        }

        DB::transaction(function (): void {
            $pending = DB::table('lifetime_award_milestones')
                ->where('status', 'pending')
                ->orderBy('id')
                ->get(['id', 'distributor_id', 'rank_number', 'tranche', 'amount_paise', 'triggered_month']);

            foreach ($pending as $row) {
                DB::table('lifetime_award_milestones')->where('id', $row->id)->update([
                    'status' => 'cancelled',
                    'notes' => (int) $row->tranche > 1 ? self::NOTE_TRANCHE : self::NOTE_MERCHANDISE,
                    'updated_at' => now(),
                ]);
            }

            $backfilled = DB::table('lifetime_award_milestones')
                ->whereNull('achieved_month')
                ->update(['achieved_month' => DB::raw('triggered_month')]);

            $cancelled = $pending->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'distributor_id' => (int) $row->distributor_id,
                'rank_number' => (int) $row->rank_number,
                'tranche' => (int) $row->tranche,
                'amount_paise' => (int) $row->amount_paise,
                'triggered_month' => (string) $row->triggered_month,
            ])->all();

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'lifetime_award_milestone',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_11_11_100200_convert_lifetime_award_milestones_to_single_cash_award',
                    'reason' => 'Client 2026-11-10: one cash award per rank with the monthly payout; merchandise milestones still pending are cancelled (no retrospective cash, user decision 2026-11-11).',
                    'rows_cancelled' => count($cancelled),
                    'rows_achieved_month_backfilled' => $backfilled,
                    'cancelled' => count($cancelled) < 500 ? $cancelled : null,
                ],
            ]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore from the plan.migration.* audit row (cancelled[].id lists every row cancelled).'
        );
    }
};
