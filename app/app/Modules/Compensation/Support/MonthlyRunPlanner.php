<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * What the monthly run owes tonight — the crediting close, the payout batch, or
 * neither — and what it deliberately defers.
 *
 * Two phases, always in this order: the close first, the payout second. The
 * payout gate reads the crediting engines' own run rows, so a payout planned
 * BEFORE the close ran would be judged against yesterday's state and refuse a
 * month the same night's close is about to complete.
 *
 * The phases differ in one way that matters: the close waits on tonight's
 * nightly run (and on the Tuesday batch when one is owed), because the client's
 * ordering rule is daily → weekly → monthly; the payout phase waits on nothing
 * tonight, because the 8th pays what the 1st credited and neither of tonight's
 * runs can change that.
 *
 * Nothing here writes: the command owns the alerts, the run rows and the exit
 * code. A planner that could write could not be asked a question from the
 * scheduler process, which is exactly what {@see self::isDue()} is for.
 */
final class MonthlyRunPlanner
{
    public function __construct(
        private readonly EngineStatusService $status,
        private readonly RunPrerequisites $prerequisites,
    ) {}

    /**
     * Should the scheduler start the monthly run at all tonight?
     *
     * Deliberately {@see self::closeOwed()} and not {@see self::closePhase()}:
     * a prerequisite deferral must still START the run, because the `skipped`
     * row and the alert are how anybody learns the month is waiting. What this
     * must not do is start a run every night for a month that is genuinely
     * incomplete — that is the coverage question, and it is answered here.
     *
     * Four reasons to run: it is the 1st; a closable month is still open
     * (self-heal); the batch due from the 8th is missing; or last month's batch
     * was never built and the month it would pay actually traded (D1b — catch
     * up, never invent).
     */
    public function isDue(Carbon $night): bool
    {
        $thisBatchMonth = $night->copy()->startOfMonth();
        $lastBatchMonth = $thisBatchMonth->copy()->subMonthNoOverflow();

        return $night->day === 1
            || $this->closeOwed($night)
            || ($night->day >= 8 && ! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $thisBatchMonth))
            || (! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $lastBatchMonth)
                && ! MonthlyEngineCompletionGate::owedNoCrediting($lastBatchMonth->copy()->subMonthNoOverflow()));
    }

    /**
     * Phase 1 — the crediting close for the month that has just ended.
     *
     * Order of checks, and each one is a different answer:
     *   1. already closed → nothing owed;
     *   2. closed to credits → nothing owed either, and BEFORE the
     *      prerequisites. Such a month does not always carry a
     *      `compensation.monthly-close` row (the engines can be run by hand, a
     *      close that died mid-way can be finished by hand, and every month
     *      survives a full recompute, which truncates `engine_runs` and replays
     *      the leaf engines only). Without this the monthly run would plan a
     *      close for a month nothing may be credited into, and the close
     *      command would refuse it every night with a refusal that has no
     *      override and that nobody can clear — a deferral nightly, for as long
     *      as the condition holds. The question asked is
     *      {@see FrozenPayoutGuard::creditingRefusal()}, exactly the one the
     *      close command asks: a planner that asked a narrower one (frozen
     *      only) would keep planning a month whose batch is built and awaiting
     *      approval, and be refused on it every night;
     *   3. the prerequisites — tonight's nightly run green, and the weekly run
     *      green when a Tuesday batch is owed tonight (client, 2026-09-18);
     *   4. GSB off → step anyway: the cut-off computes nothing while the flag
     *      is off, so the month owes none and demanding them would deadlock
     *      every close;
     *   5. a cut-off in flight → defer: it commits per distributor, so closing
     *      beside one prices the month against a day still being written;
     *   6. coverage → step, or defer naming how many days are missing.
     *
     * A month with no sales is still CLOSED when its days are whole: the
     * crediting engines' own prior-month gates (R-76) read a closed month, and
     * skipping the close would strand them. Only the PAYOUT waves such a month
     * through (D1).
     *
     * $ignorePrerequisites is the monthly run's `--force`, and it is scoped to
     * check 3 alone: the ordering rule is the client's, and an operator typing
     * the command by hand at noon is entitled to overrule it. Nothing else here
     * is overridable — a month whose days are not all cut off stays deferred,
     * because every monthly engine freezes what it computes and a month closed
     * short stays short. Answered here rather than in the command so the
     * command never has to re-derive which month would have been stepped.
     */
    public function closePhase(Carbon $night, bool $ignorePrerequisites = false): MonthlyRunPhase
    {
        $month = $night->copy()->startOfMonth()->subMonthNoOverflow();

        if ($this->status->hasSucceededRun('compensation.monthly-close', $month)) {
            return new MonthlyRunPhase;
        }

        if (FrozenPayoutGuard::creditingRefusal($month) !== null) {
            return new MonthlyRunPhase;
        }

        $refusal = $ignorePrerequisites
            ? null
            : $this->prerequisites->nightlyRunRefusal($night)
                ?? $this->prerequisites->weeklyRunRefusal($night);

        if ($refusal !== null) {
            return new MonthlyRunPhase([], [new MonthlyRunDeferral('close', $month, sprintf(
                "%s was not closed tonight: %s\nThe monthly run closes it on the first night both are green; "
                .'nothing to type.',
                $month->format('F Y'),
                $refusal,
            ), null, null, 'prerequisite')]);
        }

        if (! Feature::for(null)->active(GenosSalesBonusFeature::class)) {
            return new MonthlyRunPhase([$month]);
        }

        if ($this->status->hasRunInFlight('gsb.daily-cutoff')) {
            return new MonthlyRunPhase([], [new MonthlyRunDeferral('close', $month, sprintf(
                '%s was not closed tonight: a GSB daily cut-off is still running, and it commits per distributor, '
                ."so closing beside it would price the month against a day still being written.\n"
                .'The monthly run closes it on the next night.',
                $month->format('F Y'),
            ), null, null, 'cutoff_in_flight')]);
        }

        $coverage = MonthCutoffCoverage::for($month, $this->status);

        return $coverage->isWhole()
            ? new MonthlyRunPhase([$month])
            : new MonthlyRunPhase([], [new MonthlyRunDeferral(
                'close',
                $month,
                $coverage->refusal(),
                null,
                $coverage->missing(),
                'coverage',
            )]);
    }

    /**
     * Phase 2 — the monthly payout batch, planned AFTER phase 1 has run.
     *
     * No prerequisite on tonight's runs by design: the 8th pays what the 1st
     * credited, and neither tonight's cut-off nor tonight's Tuesday batch can
     * change a figure it sweeps.
     *
     * Two branches. The MAIN one is the payment due from the 8th, and it runs
     * whether or not the crediting month traded: it is also what sweeps a
     * credit from an earlier month whose hold (KYC, bank, below 3,000 BV) has
     * since cleared. The LOOKBACK catches up a batch nobody built, and it is
     * bounded by D1b — without that bound a platform that opened in October
     * would build an empty September-dated batch for a month in which the
     * company did not exist, in front of finance.
     *
     * OLDEST FIRST, AND NEVER PAST A DEFERRAL (A7). The monthly sweep window is
     * `WalletLedgerEntry::earnedForMonthOrBefore($batchMonth)`, so a batch for
     * month M also collects every unswept monthly credit of EARLIER months.
     * Building M while M−1 is deferred would therefore pay M−1's partially
     * computed credits under M's batch — and the engine M−1 is still waiting on
     * would credit the rest into a month nothing sweeps again. The main month
     * is recorded as its own deferral naming the older one instead, so the
     * alert says which month is actually blocking.
     */
    public function payoutPhase(Carbon $night): MonthlyRunPhase
    {
        $months = [];
        $deferrals = [];
        $thisBatchMonth = $night->copy()->startOfMonth();
        $lastBatchMonth = $thisBatchMonth->copy()->subMonthNoOverflow();
        $olderDeferral = null;

        if (! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $lastBatchMonth)) {
            $older = $lastBatchMonth->copy()->subMonthNoOverflow();

            if (! MonthlyEngineCompletionGate::owedNoCrediting($older)) {
                $olderDeferral = $this->consider($older, $months, $deferrals);
            }
        }

        if ($night->day >= 8 && ! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $thisBatchMonth)) {
            $main = $thisBatchMonth->copy()->subMonthNoOverflow();

            if ($olderDeferral === null) {
                $this->consider($main, $months, $deferrals);
            } else {
                $deferrals[] = new MonthlyRunDeferral('payout', $main, sprintf(
                    "The %s payout waits on %s: %s\nA monthly batch sweeps every unswept monthly credit earned in "
                    .'its own month or earlier, so building %s tonight would pay out %s on figures its engines have '
                    ."not finished writing.\nThe monthly run builds both, oldest first, on any night after that is "
                    .'fixed.',
                    $main->format('F Y'),
                    $olderDeferral->month->format('F Y'),
                    $olderDeferral->reason,
                    $main->format('F Y'),
                    $olderDeferral->month->format('F Y'),
                ), $olderDeferral->engineKey);
            }
        }

        return new MonthlyRunPhase($months, $deferrals);
    }

    /**
     * Is the month that ended most recently still unclosed, and closable?
     *
     * Pure — no alert, no row — because the scheduler asks it every night at
     * 04:00 and an answer that wrote would fill the audit log with the same
     * sentence for as long as the condition held.
     *
     * A month nothing may be credited into is the same answer as a closed one,
     * and for the same reason it is in {@see self::closePhase()}: it may carry
     * no close row at all, and no crediting close could ever be run for it
     * again. Asking for one would start the monthly run every night, to be
     * refused every night by a guard with no override. Same question as the
     * close command asks, so the two can never disagree.
     */
    private function closeOwed(Carbon $night): bool
    {
        $month = $night->copy()->startOfMonth()->subMonthNoOverflow();

        if ($this->status->hasSucceededRun('compensation.monthly-close', $month)) {
            return false;
        }

        if (FrozenPayoutGuard::creditingRefusal($month) !== null) {
            return false;
        }

        if (! Feature::for(null)->active(GenosSalesBonusFeature::class)) {
            return true;
        }

        return MonthCutoffCoverage::for($month, $this->status)->isWhole();
    }

    /**
     * One crediting month, judged by the completion gate: a step when the month
     * is complete, a deferral naming the engine that is not.
     *
     * Returns the deferral it recorded, or null when the month was stepped —
     * the caller needs that answer to hold the newer month back (A7), and
     * asking the gate a second time would be a second predicate to keep in step
     * with this one.
     *
     * @param  list<Carbon>  $months
     * @param  list<MonthlyRunDeferral>  $deferrals
     */
    private function consider(Carbon $crediting, array &$months, array &$deferrals): ?MonthlyRunDeferral
    {
        $blocker = MonthlyEngineCompletionGate::blockingFailure($crediting);

        if ($blocker === null) {
            $months[] = $crediting;

            return null;
        }

        $deferral = new MonthlyRunDeferral('payout', $crediting, sprintf(
            "The %s payout was not built: %s\nThe monthly run builds it on any night after that is fixed.",
            $crediting->format('F Y'),
            $blocker['message'],
        ), $blocker['engine_key']);

        $deferrals[] = $deferral;

        return $deferral;
    }
}
