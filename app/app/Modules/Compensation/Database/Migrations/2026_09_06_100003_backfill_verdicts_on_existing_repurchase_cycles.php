<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Settle every cycle written before the client's 2026-09-06 rules, so the
     * new verdict never re-judges a closed window under conditions that did not
     * apply while it was open.
     *
     * Two things would otherwise go wrong:
     *
     *   • `IncomeEligibilityService::verdictAsOf()` reads `fulfilled_on` to
     *     decide whether a past window held anything. Every legacy row has it
     *     null, so a window that completed cleanly under the old rules would
     *     read as held for every date after its due date.
     *   • `RepurchaseCycleService::refresh()` resolves any window with a null
     *     `resolved_at`. On a legacy row that would apply the NEW wallet = ₹0
     *     condition to a window that closed months ago, retroactively
     *     withholding income for an obligation nobody was told about.
     *
     * So: a completed legacy cycle is stamped as fulfilled on its own due date,
     * and a lapsed one is stamped as a BV shortfall — which is exactly what it
     * was, since the wallet was judged elsewhere at the time. `wallet_zeroed` is
     * left NULL rather than guessed: the old engine never recorded it, and the
     * honest answer is "not measured", which is also the fail-open answer.
     * Cycles still inside their window are untouched — they resolve normally.
     *
     * That last sentence is enforced by `due_date < today`, which the first
     * version of this migration only claimed. Without it the backfill settled
     * every in-flight window it found as fulfilled on its own due date,
     * against conditions it never measured — permanently, because
     * `RepurchaseCycleService::refresh()` only ever undoes a premature FAILED
     * verdict, never a premature completed one. Seven live staging cycles were
     * settled that way on 2026-09-10 (QA F21); production has not run this
     * migration, so adding the guard here is the fix rather than a second
     * migration undoing the first.
     *
     * A window due TODAY is still in flight — it closes at 23:59 — so the
     * comparison is strictly less-than.
     */
    public function up(): void
    {
        $today = Carbon::today()->toDateString();

        DB::table('repurchase_cycles')
            ->whereNull('resolved_at')
            ->whereDate('due_date', '<', $today)
            ->where('status', 'completed')
            ->update([
                'fulfilled_on' => DB::raw('due_date'),
                'resolved_at' => DB::raw('COALESCE(completed_at, updated_at)'),
                'failure_reason' => null,
            ]);

        DB::table('repurchase_cycles')
            ->whereNull('resolved_at')
            ->whereDate('due_date', '<', $today)
            ->whereIn('status', ['grace', 'suspended'])
            ->update([
                'failure_reason' => 'bv_short',
                // `updated_at` is NOT NULL on this table, so no COALESCE — and
                // a double-quoted fallback would be an identifier on SQLite.
                'resolved_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        // The columns themselves are dropped by the migration that added them.
    }
};
