<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use App\Modules\Compensation\Services\IncomeEligibilityService;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A monthly engine was asked to freeze while an earner's repurchase cycle, due
 * on or before the month end, still has no verdict (fail-safe principle 2).
 *
 * An unresolved cycle reads as ELIGIBLE in
 * {@see IncomeEligibilityService::verdictAsOf()}
 * — the fail-open that stops a lagging daily command from forfeiting everyone's
 * income. For a month-end freeze that is the overpaying direction: the roster
 * is decided once and never re-judged, so a distributor about to be failed by
 * the 00:05 `repurchase:evaluate` would be paid for good. The engine refuses
 * instead, before any write; the remedy is to run the evaluation and re-run.
 *
 * The monthly close reaches the engines at 04:00, after the 00:05 evaluation,
 * so in production only a manual early trigger meets this. The command records
 * it as a FAILED run carrying this message (a re-run after the evaluation
 * fixes it, so it is not a `skipped` refusal).
 */
final class RepurchaseVerdictsPending extends RuntimeException
{
    /**
     * @param  list<int>  $pendingDistributorIds
     */
    public static function forMonthEnd(string $engine, Carbon $monthEnd, array $pendingDistributorIds): self
    {
        return new self(sprintf(
            '%s %s: %d earner(s) have a repurchase cycle due on or before %s with no verdict yet (ids %s). Run repurchase:evaluate first.',
            $engine,
            $monthEnd->format('Y-m'),
            count($pendingDistributorIds),
            $monthEnd->toDateString(),
            implode(',', array_slice($pendingDistributorIds, 0, 20)),
        ));
    }
}
