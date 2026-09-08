<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Events\IncomeSuspended;
use App\Modules\Compensation\Events\RepurchaseCompleted;
use App\Modules\Compensation\Events\RepurchaseCycleOpened;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manages each distributor's repurchase obligation cycle (client 2026-09-07).
 *
 * A cycle is a fixed 30-day window (`comp.repurchase.cycle_days`) anchored on
 * the date the distributor first reached 600 BV of personal purchase. That day
 * is day 0 and the due date falls `cycle_days` days AFTER it — start 7 Jul,
 * due 6 Aug, as in every one of the client's worked examples. It passes only if
 * BOTH of the client's conditions hold:
 *
 *   (A) self-purchase BV inside the window >= the rank's obligation, and
 *   (B) the repurchase wallet stood at zero on the window's LAST day.
 *
 * Condition (B) is only knowable on that last day, so a cycle can never
 * complete early: it stays `active` until its window closes, then resolves
 * once and freezes `wallet_balance_paise` / `wallet_zeroed` onto the row. That
 * freeze is what lets a bonus month be re-run and reach the same verdict as the
 * run that paid it — the job `repurchase_monthly_snapshots` used to do for the
 * old calendar-month deadline, moved onto the window it belongs to.
 *
 * There is NO grace window: a cycle that misses its due date is failed from
 * `due + 1` onwards ({@see RepurchaseCycle::forfeitedWindow()}). The
 * distributor keeps accumulating, and on the first day both conditions hold
 * again the cycle completes ({@see IncomeReactivated}) and a brand-new
 * full-length window opens ON that day. One rule covers both paths:
 * `next_start = max(due_date + 1, fulfilled_on)`.
 *
 * Every value is read through {@see CompensationPlanSettingsService} (SSOT) and
 * every state transition emits a fire-and-forget domain event.
 */
final class RepurchaseCycleService
{
    /** Safety bound on the catch-up roll loop (months). */
    private const MAX_ROLL = 120;

    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly BvLedgerService $bvLedger,
        private readonly WalletService $wallet,
    ) {}

    /** Highest rank the distributor has qualified for (sticky); 0 = non-ranked. */
    public function currentRank(int $distributorId): int
    {
        return (int) RankQualification::query()
            ->where('distributor_id', $distributorId)
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->max('rank_number');
    }

    /** Monthly repurchase BV obligation (paise) for this distributor's rank. */
    public function requiredBvPaise(int $distributorId): int
    {
        $rank = $this->currentRank($distributorId);

        return $rank > 0
            ? $this->plan->rankRepurchaseBvPaise($rank)
            : $this->plan->nonRankedRepurchaseBvPaise();
    }

    /**
     * The distributor's repurchase anchor = the date they first reached the
     * 600-BV personal-purchase minimum (5 Jul 2026 rule; previously the 3,000-BV
     * Retailer title). Null until they reach 600 BV (no obligation before that).
     */
    public function repurchaseAnchor(int $distributorId): ?Carbon
    {
        return $this->bvLedger->firstReachedBvPaiseAt($distributorId, $this->plan->gsbMinBvPaise());
    }

    /**
     * The distributor's newest cycle, read-only — what the UI needs in order to
     * name THEIR deadline rather than a calendar month end. Null before they
     * reach 600 BV, when no obligation exists yet.
     */
    public function currentCycle(int $distributorId): ?RepurchaseCycle
    {
        return RepurchaseCycle::query()
            ->where('distributor_id', $distributorId)
            ->orderByDesc('cycle_start_date')
            ->first();
    }

    /**
     * Open/advance the distributor's repurchase cycle as of $asOf, refreshing
     * both conditions and emitting transition events. Returns the open cycle,
     * or null if the distributor has never reached 600 BV (no obligation yet).
     */
    public function evaluate(int $distributorId, Carbon $asOf): ?RepurchaseCycle
    {
        $asOf = $asOf->copy()->startOfDay();

        // Check for an existing cycle first — a cycle already existing proves
        // the distributor passed the 600-BV anchor, so skip the expensive
        // firstReachedBvPaiseAt() scan (N+1 on bv_ledger_entries) on every run
        // after the first cycle is opened.
        $cycle = RepurchaseCycle::query()
            ->where('distributor_id', $distributorId)
            ->orderByDesc('cycle_start_date')
            ->first();

        if ($cycle === null) {
            $anchor = $this->repurchaseAnchor($distributorId);
            if ($anchor === null || $anchor->greaterThan($asOf)) {
                return null;
            }
            $cycle = $this->openCycle($distributorId, $anchor->copy()->startOfDay());
        }

        // Advance through elapsed-and-fulfilled cycles until the open cycle
        // covers $asOf. The guard caps catch-up and, by failing the roll
        // condition rather than opening a new row, guarantees the returned
        // cycle is always one that was just refreshed.
        $guard = 0;
        while (true) {
            $this->refresh($cycle, $asOf);

            $nextStart = $this->nextCycleStart($cycle);

            if ($nextStart !== null
                && $asOf->greaterThanOrEqualTo($nextStart)
                && ++$guard <= self::MAX_ROLL) {
                $cycle = $this->openCycle($distributorId, $nextStart);

                continue;
            }
            break;
        }

        return $cycle;
    }

    /**
     * The day the distributor's next window opens, or null while the current
     * one is unresolved. A cycle fulfilled AFTER its due date re-anchors
     * the next window onto the fulfilment day itself; an on-time cycle simply
     * hands over the day after it ends.
     */
    private function nextCycleStart(RepurchaseCycle $cycle): ?Carbon
    {
        if ($cycle->status !== RepurchaseCycle::STATUS_COMPLETED || $cycle->fulfilled_on === null) {
            return null;
        }

        $dayAfterDue = $cycle->due_date->copy()->startOfDay()->addDay();
        $fulfilled = $cycle->fulfilled_on->copy()->startOfDay();

        return $fulfilled->greaterThan($dayAfterDue) ? $fulfilled : $dayAfterDue;
    }

    /**
     * Create + persist a fresh cycle starting on $start; emits the opened event.
     * The required BV is snapshotted from the distributor's rank at cycle-open;
     * a rank change mid-cycle takes effect from the next cycle.
     */
    private function openCycle(int $distributorId, Carbon $start): RepurchaseCycle
    {
        $start = $start->copy()->startOfDay();
        $due = $start->copy()->addDays($this->plan->repurchaseCycleDays());

        $cycle = RepurchaseCycle::create([
            'distributor_id' => $distributorId,
            'cycle_start_date' => $start->toDateString(),
            'due_date' => $due->toDateString(),
            'required_bv_paise' => $this->requiredBvPaise($distributorId),
            'completed_bv_paise' => 0,
            'status' => RepurchaseCycle::STATUS_ACTIVE,
        ]);

        event(new RepurchaseCycleOpened($distributorId, $cycle->id, $start->toDateString()));

        return $cycle;
    }

    /**
     * Recompute one cycle's conditions as of $asOf and persist it.
     *
     * Three shapes, in order:
     *  - the window is still open — only the running BV moves, never the status;
     *  - the window has just closed — resolve BOTH conditions at the window's
     *    last instant and freeze them; this happens exactly once per cycle;
     *  - the window closed and failed — hunt for the first day since on which
     *    both conditions hold again (the late fulfilment).
     */
    private function refresh(RepurchaseCycle $cycle, Carbon $asOf): void
    {
        $start = $cycle->cycle_start_date->copy()->startOfDay();
        $dueEnd = $cycle->due_date->copy()->endOfDay();
        $previous = $cycle->status;

        if ($asOf->lessThanOrEqualTo($cycle->due_date->copy()->startOfDay())) {
            // Window still open. A cycle cannot be judged early: condition (B)
            // asks about the wallet on the LAST day, which has not happened.
            $cycle->completed_bv_paise = $this->bvLedger->selfPurchaseBvPaise(
                $cycle->distributor_id,
                $start,
                $asOf->copy()->endOfDay(),
            );

            // Only an unresolved cycle is active. A cycle written before the
            // 2026-09-06 rules could complete as soon as its BV landed, so a
            // legacy row can reach this branch already resolved — and demoting
            // it back to active would discard a verdict that has already been
            // acted on, then re-resolve it later against the wrong window.
            //
            // A FAILED verdict inside an open window is different: nothing can
            // fail a cycle before its last day, so it can only have come from
            // a run dated after the window (the recompute tool replaying into
            // next month). Left standing, the real-clock run would keep it —
            // frozen wallet balance included — and forfeit every day from the
            // due date onward no matter what the distributor does. Undo it and
            // let the window be judged when it really closes.
            if ($cycle->resolved_at === null) {
                $cycle->status = RepurchaseCycle::STATUS_ACTIVE;
            } elseif ($cycle->status !== RepurchaseCycle::STATUS_COMPLETED) {
                AuditLog::create([
                    'actor_id' => null,
                    'action' => 'repurchase.cycle.premature_verdict_reset',
                    'subject_type' => 'distributor',
                    'subject_id' => $cycle->distributor_id,
                    'details' => [
                        'cycle_id' => $cycle->id,
                        'due_date' => $cycle->due_date->toDateString(),
                        'as_of' => $asOf->toDateString(),
                        'discarded' => [
                            'status' => $cycle->status,
                            'failure_reason' => $cycle->failure_reason,
                            'wallet_balance_paise' => $cycle->wallet_balance_paise,
                            'resolved_at' => $cycle->resolved_at instanceof Carbon ? $cycle->resolved_at->toDateTimeString() : null,
                        ],
                        'reason' => 'failed verdict frozen inside a window that had not closed (future-dated replay); undone by the real-clock run',
                    ],
                ]);

                $cycle->status = RepurchaseCycle::STATUS_ACTIVE;
                $cycle->resolved_at = null;
                $cycle->wallet_balance_paise = null;
                $cycle->wallet_zeroed = null;
                $cycle->failure_reason = null;
                $cycle->fulfilled_on = null;
            }

            $cycle->save();

            return;
        }

        if ($cycle->resolved_at === null) {
            $this->resolveAtWindowEnd($cycle, $start, $dueEnd);
        }

        if ($cycle->status !== RepurchaseCycle::STATUS_COMPLETED) {
            $this->applyLateFulfilment($cycle, $start, $asOf);
        }

        if ($cycle->status !== $previous) {
            if ($cycle->status === RepurchaseCycle::STATUS_COMPLETED && $cycle->completed_at === null) {
                $cycle->completed_at = Carbon::now();
            }
            $this->onTransition($cycle, $previous, $cycle->status);
        }

        $cycle->save();
    }

    /**
     * The one-time verdict, taken at the window's last instant and frozen.
     * Re-running a past month must not be able to move it.
     */
    private function resolveAtWindowEnd(RepurchaseCycle $cycle, Carbon $start, Carbon $dueEnd): void
    {
        $bv = $this->bvLedger->selfPurchaseBvPaise($cycle->distributor_id, $start, $dueEnd);
        $walletPaise = $this->walletBalanceAt($cycle->distributor_id, $dueEnd);

        $bvMet = $bv >= $cycle->required_bv_paise;
        $walletZeroed = $walletPaise <= 0;

        $cycle->completed_bv_paise = $bv;
        $cycle->wallet_balance_paise = max(0, $walletPaise);
        $cycle->wallet_zeroed = $walletZeroed;
        $cycle->resolved_at = Carbon::now();

        if ($bvMet && $walletZeroed) {
            $cycle->status = RepurchaseCycle::STATUS_COMPLETED;
            $cycle->fulfilled_on = $cycle->due_date->copy()->startOfDay();
            $cycle->failure_reason = null;

            return;
        }

        $cycle->failure_reason = match (true) {
            ! $bvMet && ! $walletZeroed => RepurchaseCycle::REASON_BOTH,
            ! $bvMet => RepurchaseCycle::REASON_BV_SHORT,
            default => RepurchaseCycle::REASON_WALLET_NONZERO,
        };
        $cycle->status = RepurchaseCycle::STATUS_SUSPENDED;
    }

    /**
     * After a failed window the distributor keeps accumulating against the SAME
     * obligation. On the first day both conditions hold again the cycle
     * completes and a fresh window opens on that day; every day in between is
     * forfeited.
     *
     * The scan walks days rather than trusting $asOf, because a catch-up replay
     * would otherwise stamp today's date onto a fulfilment that happened weeks
     * ago and shift every window after it. Two queries, however long the gap.
     */
    private function applyLateFulfilment(RepurchaseCycle $cycle, Carbon $start, Carbon $asOf): void
    {
        $from = $cycle->due_date->copy()->startOfDay()->addDay();

        if ($from->greaterThan($asOf)) {
            return;
        }

        // Re-derive the window-end BV from the ledger rather than reading
        // completed_bv_paise: this method advances that column past the window
        // end, so a second run would otherwise start from a total that already
        // includes the days it is about to add again. The wallet base IS safe to
        // read — wallet_balance_paise is frozen once, at the window end, and
        // never written here.
        $bv = $this->bvLedger->selfPurchaseBvPaise(
            $cycle->distributor_id,
            $start,
            $cycle->due_date->copy()->endOfDay(),
        );

        // The wallet base must never come from `?? 0`. A cycle resolved before
        // this column existed carries NULL here — deliberately, because the old
        // engine never measured it — and reading that as "the wallet was clear"
        // passed condition (B) for a distributor who was holding repurchase
        // money. Derive it from the ledger instead, and freeze it while we are
        // here so the row stops being ambiguous.
        $walletPaise = $cycle->wallet_balance_paise;

        if ($walletPaise === null) {
            $walletPaise = $this->walletBalanceAt(
                $cycle->distributor_id,
                $cycle->due_date->copy()->endOfDay(),
            );
            $cycle->wallet_balance_paise = max(0, $walletPaise);
            $cycle->wallet_zeroed = $walletPaise <= 0;
        }

        $walletPaise = (int) $walletPaise;

        $bvByDay = $this->selfPurchaseBvByDay($cycle->distributor_id, $from, $asOf);
        $walletByDay = $this->repurchaseWalletDeltaByDay($cycle->distributor_id, $from, $asOf);

        for ($day = $from->copy(); $day->lessThanOrEqualTo($asOf); $day->addDay()) {
            $key = $day->toDateString();
            $bv += $bvByDay[$key] ?? 0;
            $walletPaise += $walletByDay[$key] ?? 0;

            if ($bv >= $cycle->required_bv_paise && $walletPaise <= 0) {
                $cycle->completed_bv_paise = $bv;
                $cycle->status = RepurchaseCycle::STATUS_COMPLETED;
                $cycle->fulfilled_on = $day->copy()->startOfDay();

                return;
            }
        }

        $cycle->completed_bv_paise = $bv;
        $cycle->status = RepurchaseCycle::STATUS_SUSPENDED;
    }

    /**
     * The repurchase wallet balance at an instant — condition 4(B)'s input.
     * One place, so the window-end freeze and the late-fulfilment scan can
     * never answer the question differently.
     */
    private function walletBalanceAt(int $distributorId, Carbon $at): int
    {
        return $this->wallet->repurchaseWalletBalancesAsOfPaise([$distributorId], $at)[$distributorId] ?? 0;
    }

    /**
     * Self-purchase BV per calendar day. Same definition as
     * {@see BvLedgerService::selfPurchaseBvPaise()} — accruals net of reversals
     * on self-consumption orders — grouped so the late-fulfilment scan costs one
     * query instead of one per day.
     *
     * @return array<string, int> Y-m-d => paise
     */
    private function selfPurchaseBvByDay(int $distributorId, Carbon $from, Carbon $to): array
    {
        return DB::table('bv_ledger_entries')
            ->where('distributor_id', $distributorId)
            ->whereIn('type', ['accrual', 'reversal'])
            ->whereBetween('effective_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.id', 'bv_ledger_entries.order_id')
                    ->where('orders.self_consumption', true);
            })
            ->selectRaw('DATE(effective_at) AS day, SUM(bv_paise) AS total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * Repurchase-wallet movement per calendar day, signed the same way
     * {@see WalletService::repurchaseWalletBalancesAsOfPaise()} sums it:
     * `repurchase_deduction` credits in, `repurchase_wallet_used` debits out.
     *
     * @return array<string, int> Y-m-d => paise
     */
    private function repurchaseWalletDeltaByDay(int $distributorId, Carbon $from, Carbon $to): array
    {
        return DB::table('wallet_ledger_entries')
            ->where('distributor_id', $distributorId)
            ->whereIn('type', WalletService::REPURCHASE_TYPES)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw("DATE(created_at) AS day, COALESCE(SUM(CASE WHEN type = 'repurchase_deduction' THEN amount_paise ELSE -ABS(amount_paise) END), 0) AS total")
            ->groupBy('day')
            ->pluck('total', 'day')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    private function onTransition(RepurchaseCycle $cycle, string $from, string $to): void
    {
        $distributorId = $cycle->distributor_id;

        match ($to) {
            RepurchaseCycle::STATUS_SUSPENDED => event(new IncomeSuspended($distributorId, $cycle->id)),
            RepurchaseCycle::STATUS_COMPLETED => $this->onCompleted($cycle, $from),
            default => null,
        };
    }

    private function onCompleted(RepurchaseCycle $cycle, string $from): void
    {
        // Pass the prior status so listeners can distinguish an on-time close
        // from a late fulfilment that has forfeited days behind it.
        event(new RepurchaseCompleted($cycle->distributor_id, $cycle->id, $from));

        if ($from === RepurchaseCycle::STATUS_SUSPENDED) {
            event(new IncomeReactivated($cycle->distributor_id, $cycle->id));
        }
    }
}
