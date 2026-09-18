<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Services\EngineStatusService;
use Illuminate\Support\Carbon;

/**
 * "Is every day this month owed a cut-off actually cut off?" — asked once, in
 * one place, by everyone who needs the answer.
 *
 * Two callers decide the same thing from it and must never disagree: the
 * monthly close's own preflight, and the planner that decides whether tonight
 * is the night to run that close. A second copy of the arithmetic is how a
 * planner queues a close the close then refuses, every night, for ever.
 *
 * Owed days, not calendar days ({@see PlatformStart}): a day with no
 * distributor on it has nobody to match and nothing to carry forward, so it is
 * provably vacuous — and demanding it is what makes a launch month impossible
 * to close. Covered days are the run log's own proof of a COMPLETED cut-off
 * ({@see EngineStatusService::completedCutoffDatesBetween()}), never the
 * presence of result rows: the cut-off commits per distributor, so a run that
 * died half way through leaves a day that looks finished.
 *
 * `min($covered, $owedDays)` because the coverage query spans the owed window
 * only, but a legacy or hand-run cut-off could in principle be counted twice if
 * the window ever widened; the count may never exceed what is owed, or
 * `missing()` would report a negative debt as a surplus.
 */
final readonly class MonthCutoffCoverage
{
    private function __construct(
        public Carbon $month,
        public ?Carbon $owedFrom,
        public Carbon $lastDay,
        public int $owedDays,
        public int $coveredDays,
    ) {}

    public static function for(Carbon $month, EngineStatusService $status): self
    {
        $monthStart = $month->copy()->startOfMonth();
        $lastDay = $monthStart->copy()->endOfMonth()->startOfDay();
        $owedFrom = PlatformStart::owedFrom($monthStart);

        // No distributor existed on any day of this month: the month owes no
        // cut-off at all, and is therefore whole.
        if ($owedFrom === null) {
            return new self($monthStart, null, $lastDay, 0, 0);
        }

        $owedDays = $lastDay->day - $owedFrom->day + 1;
        $covered = count(array_unique($status->completedCutoffDatesBetween($owedFrom, $lastDay)));

        return new self($monthStart, $owedFrom, $lastDay, $owedDays, min($covered, $owedDays));
    }

    public function missing(): int
    {
        return max(0, $this->owedDays - $this->coveredDays);
    }

    public function isWhole(): bool
    {
        return $this->missing() === 0;
    }

    /**
     * Why the month may not be closed — the operator-facing sentence, with the
     * command that fixes it.
     *
     * Every monthly engine prices the month from the cut-off results and then
     * FREEZES what it computed, so a month closed three days short is a month
     * permanently priced short: a re-run reuses the frozen pool rather than
     * repairing it.
     */
    public function refusal(): string
    {
        $message = sprintf(
            '%d of the %d days in %s have no completed cut-off. Every monthly engine prices the month from '
            ."those results and freezes what it computes, so closing now would price %s short for good.\n"
            .'Run php artisan gsb:daily-cutoff --date=<day> for each missing day — the Engine Runs page lists '
            .'them — and then run this close again.',
            $this->missing(),
            $this->owedDays,
            $this->month->format('F Y'),
            $this->month->format('F Y'),
        );

        // Said only when the two differ, so the count above cannot read as a
        // miscount of the calendar.
        if ($this->owedFrom !== null && $this->owedFrom->greaterThan($this->month)) {
            $message .= sprintf(
                "\nDays before %s are not owed one: the platform had no distributor on them.",
                $this->owedFrom->format('d M Y'),
            );
        }

        return $message;
    }
}
