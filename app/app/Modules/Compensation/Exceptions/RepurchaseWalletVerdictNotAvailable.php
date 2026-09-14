<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use App\Modules\Compensation\Services\RepurchaseWalletGateService;
use App\Modules\Compensation\Support\OpenMonthGuard;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * An engine asked for a month-end repurchase-wallet verdict before the month
 * ended.
 *
 * The verdict is "did this distributor still hold repurchase-wallet money at the
 * last instant of the month?" — a question a month still in flight has no answer
 * to. Asked anyway, the ledger sum degrades to "as of right now", which is a
 * different question with a different answer, and the difference is not
 * academic: the engines that run inside one monthly close WRITE repurchase
 * deductions, so Growth Booster and Fortune would count money credited minutes
 * earlier by Rank Bonus and refuse a month the distributor had actually cleared
 * (staging, 14 Sep 2026).
 *
 * Rather than answer a question it cannot answer, the gate refuses. In
 * production nothing reaches this: every monthly engine already refuses an open
 * month ({@see OpenMonthGuard}), and the
 * scheduler runs them on the 1st. It is a tripwire for the one remaining way in
 * — a hand-typed `--in-flight` run — and the answer there is a recompute, which
 * replays the whole calendar at the scheduler's own clock.
 *
 * Display surfaces never see this: they ask
 * {@see RepurchaseWalletGateService::standingAtMonthEnd()},
 * which answers "as things stand" and never throws.
 */
final class RepurchaseWalletVerdictNotAvailable extends RuntimeException
{
    public static function forOpenMonth(Carbon $month): self
    {
        return new self(sprintf(
            'The month-end repurchase-wallet verdict for %s cannot be computed: the month has not ended (it ends %s '
                .'IST). Money credited later this month — including by the engines of this very close — would count '
                .'against a balance the month has not finished forming, so the answer would be wrong rather than '
                .'provisional. Run the month once it has closed; on a test environment, use the recompute, which '
                .'replays every engine at the instant the scheduler would have fired it.',
            $month->copy()->startOfMonth()->format('F Y'),
            $month->copy()->endOfMonth()->format('d M Y 23:59'),
        ));
    }
}
