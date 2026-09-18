<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;

/**
 * What one phase of the monthly run owes tonight: the months to step, and the
 * months it deliberately did not.
 *
 * Both halves, never one: a phase that returned only the months to run would
 * make "nothing owed" and "owed but refused" the same answer — and the second
 * is the one nobody is paid for until somebody acts.
 */
final readonly class MonthlyRunPhase
{
    /**
     * @param  list<Carbon>  $months  Crediting months to step, oldest first.
     * @param  list<MonthlyRunDeferral>  $deferrals
     */
    public function __construct(
        public array $months = [],
        public array $deferrals = [],
    ) {}
}
