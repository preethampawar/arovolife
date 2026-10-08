<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Client 2026-10-09: the repurchase window is 30 days INCLUSIVE of the
     * anchor day (14 Feb → 15 Mar), so `due_date = start + cycle_days − 1`.
     * The 2026-09-07 engine (and 2026_09_11_100000) wrote `start + cycle_days`.
     * Pull every still-OPEN cycle on that old arithmetic back by one day.
     *
     * Resolved cycles (`resolved_at IS NOT NULL`) are history: their verdict is
     * frozen against their own due date and they never move. Only a row whose
     * due date is exactly `start + cycle_days` is touched, so a window already
     * on the new rule — or short for some other reason, which needs a decision
     * rather than a silent nudge — is left alone, and a second run moves
     * nothing. Row-by-row in PHP rather than a date expression, because MySQL
     * and SQLite spell date arithmetic differently.
     *
     * F-1: an open cycle whose new due date is already past is left for the
     * next `repurchase:evaluate` run to resolve, and that verdict can reach
     * back over days GSB/MSB already settled as eligible. Those ids are listed
     * in the audit row as `now_past_due_ids`. Deploy runbook: run this with
     * the queue workers and scheduler stopped (`app:deploy --maintenance`),
     * then run `repurchase:evaluate` immediately after `migrate`.
     */
    private const ACTION = 'plan.migration.redate_open_repurchase_cycles_to_29_days';

    /**
     * Below this many moved rows the audit row also lists each (id, before, after);
     * `moved_ids` is always written in full, so the restore path never depends on it.
     */
    private const LIST_LIMIT = 500;

    private const DEFAULT_CYCLE_DAYS = 30;

    public function up(): void
    {
        $cycleDays = max(1, (int) (DB::table('settings')
            ->where('key', 'comp.repurchase.cycle_days')
            ->value('value') ?? self::DEFAULT_CYCLE_DAYS));

        DB::transaction(function () use ($cycleDays): void {
            $today = Carbon::today()->toDateString();
            $moved = [];
            $nowPastDue = [];

            DB::table('repurchase_cycles')
                ->whereNull('resolved_at')
                ->select('id', 'distributor_id', 'cycle_start_date', 'due_date')
                ->orderBy('id')
                ->chunkById(500, function ($cycles) use ($cycleDays, $today, &$moved, &$nowPastDue): void {
                    foreach ($cycles as $cycle) {
                        $start = Carbon::parse((string) $cycle->cycle_start_date)->startOfDay();
                        $due = Carbon::parse((string) $cycle->due_date)->startOfDay();

                        if (! $due->isSameDay($start->copy()->addDays($cycleDays))) {
                            continue;
                        }

                        $before = $due->toDateString();
                        $after = $due->copy()->subDay()->toDateString();

                        DB::table('repurchase_cycles')
                            ->where('id', $cycle->id)
                            ->update(['due_date' => $after, 'updated_at' => Carbon::now()]);

                        $moved[] = [
                            'id' => (int) $cycle->id,
                            'distributor_id' => (int) $cycle->distributor_id,
                            'before' => $before,
                            'after' => $after,
                        ];

                        if ($after < $today) {
                            $nowPastDue[] = (int) $cycle->id;
                        }
                    }
                });

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'repurchase_cycle',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_09_100000_redate_open_repurchase_cycles_to_29_days',
                    'reason' => 'Client 2026-10-09: the repurchase window is 30 days inclusive of the start day (14 Feb → 15 Mar).',
                    'cycle_days' => $cycleDays,
                    'moved_count' => count($moved),
                    // Always the complete restore key: every moved id, however many.
                    // `after` is `before − 1 day`, so ids alone are enough to put the
                    // exact rows back; the per-row list below is a convenience only.
                    'moved_ids' => array_column($moved, 'id'),
                    'now_past_due_ids' => $nowPastDue,
                    'rows' => count($moved) < self::LIST_LIMIT ? $moved : null,
                ],
            ]);
        });
    }

    public function down(): void
    {
        // Never guess: a blanket +1 day would also move cycles opened under the
        // new rule. The audit row lists each moved id with its before value.
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore from the plan.migration.* audit row '
            .'(moved_ids lists every re-dated repurchase_cycles id; due_date goes back by one day for each).'
        );
    }
};
