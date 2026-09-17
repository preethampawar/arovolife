<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin\Concerns;

use App\Modules\Compensation\Services\Recompute\RecomputeState;
use Throwable;

/**
 * Refuse while this environment is standing on simulated compensation.
 *
 * A projection replays the scheduler past today, so every figure derived from
 * it is priced on bonuses nobody has earned yet. Reading those figures is fine;
 * acting on them as if they were real money is not — signing a payout batch off,
 * handing the bank a file built from one, or debiting a credit back out of a
 * wallet on the strength of one.
 *
 * Production never reaches this — {@see RecomputeState} answers false before
 * touching the database whenever the recompute gate is shut.
 */
trait RefusesProjectedFigures
{
    /**
     * @param  string  $whatCannotProceed  One sentence naming the action being
     *                                     refused and why the projection makes
     *                                     it unsafe. Shown between the state and
     *                                     the remedy.
     * @return string|null The operator-facing refusal, or null when the
     *                     environment holds real figures.
     */
    protected function projectedFiguresRefusal(string $whatCannotProceed): ?string
    {
        try {
            $through = app(RecomputeState::class)->projectedThrough();
        } catch (Throwable) {
            return null;
        }

        if ($through === null) {
            return null;
        }

        return sprintf(
            'This environment is holding projected compensation figures, simulated through %s. %s '
                .'Run a recompute with "Up to now", or wait for the nightly reset, and try again.',
            $through->format('d M Y H:i'),
            $whatCannotProceed,
        );
    }
}
