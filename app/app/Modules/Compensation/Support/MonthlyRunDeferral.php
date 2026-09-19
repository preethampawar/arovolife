<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;

/**
 * One thing the monthly run decided NOT to do tonight, and why.
 *
 * A deferral is never a failed night (D3): the command warns, records the
 * matching {@see NightlyRunAlert} and its own `skipped` run, and exits 0. It
 * carries everything the alert needs so the command does not have to
 * re-derive any of it — including `cause`, which is what makes the health
 * digest able to say "waiting for the cut-off" rather than "waiting", and what
 * keeps the alert from being written once and never updated when the reason
 * changes.
 */
final readonly class MonthlyRunDeferral
{
    /**
     * @param  'close'|'payout'  $kind  Which phase deferred.
     * @param  Carbon  $month  The CREDITING month — never the batch month.
     * @param  string|null  $engineKey  The engine the payout gate named, when there is one.
     * @param  int|null  $missingDays  Days with no completed cut-off, for the coverage cause only.
     * @param  'coverage'|'cutoff_in_flight'|'prerequisite'|null  $cause
     */
    public function __construct(
        public string $kind,
        public Carbon $month,
        public string $reason,
        public ?string $engineKey = null,
        public ?int $missingDays = null,
        public ?string $cause = null,
    ) {}
}
