<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use App\Modules\Compensation\Services\GsbCutoffService;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A GSB cut-off date was asked to run after a later date had already advanced
 * the rolling carry-forward store.
 *
 * The store holds one state per distributor, not one per date: each night's
 * cut-off reads it, folds that day's group BV in, and writes it forward. So a
 * date can only be computed while the store still sits on the day before it.
 * Once a later date has moved it, this date has nowhere correct to start —
 * recomputing would fold its BV in on top of a later day's, and rewinding to
 * the before-state this date's own row recorded would erase that later day.
 * Both silently, in figures nobody re-reads.
 *
 * A distinct type rather than a bare RuntimeException because the admin retry
 * catches it by name: it writes a `compensation.cutoff.manual_retry_refused`
 * audit row and shows the operator this message, and that audit action would be
 * a lie if any other failure from the same call landed under it.
 *
 * {@see GsbCutoffService::computeForDistributor()} for the guard itself, and
 * R-91 in the risk register for what is still missing behind the production
 * half of the remedy below.
 */
final class CutoffReplayedOutOfOrder extends RuntimeException
{
    /**
     * The message names the remedy per environment because there is no admin
     * control for this — the stub that claimed to be one was deleted rather
     * than left to make an operator believe the store had been repaired.
     * Rewinding needs every date from this one forward recomputed in order,
     * which only the recompute runner does — and that refuses in production by
     * design (ADR-0014), so production really is an escalation.
     */
    public static function forDate(Carbon $date, int $distributorId): self
    {
        return new self(
            "Cannot re-run the {$date->toDateString()} cut-off for distributor {$distributorId}: "
            .'a later cut-off already advanced the carry-forward store, and replaying one date in '
            .'isolation cannot rewind it. Every date from this one forward has to be recomputed in '
            .'order: on dev/staging run `php artisan compensation:recompute-all --horizon=now`; in '
            .'production escalate to the platform team — there is no admin control for this and no '
            .'self-service path.'
        );
    }
}
