<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;

/**
 * Refuses a monthly engine run for a month that has not closed yet.
 *
 * Every engine in {@see self::FREEZING_COMMANDS} freezes the month's pool and
 * roster the first time it runs and credits from that snapshot. Run on the
 * 20th, it prices the month on twenty days of BV and moves money on it; the
 * scheduled run on the 1st then finds credited rows and KEEPS the premature
 * pool (replacePrematureFreeze() only replaces an uncredited freeze), so the
 * month is permanently priced short — the 24 Aug 2026 staging incident, at
 * month scale. The admin console already refuses an in-flight period for these
 * engines; this is the same rule for the command line, which the scheduler,
 * the runbooks and the operators use.
 *
 * `--in-flight` overrides it. The recompute tool passes it for the month in
 * flight at its horizon, whose figures are provisional by construction (and
 * discarded by the next recompute); the admin console passes it only behind
 * the developer testing gate. An operator typing it by hand is stating that
 * they mean to freeze a partial month.
 */
final class OpenMonthGuard
{
    public const OPTION = 'in-flight';

    /** @var list<string> command signatures that freeze a month's economics */
    public const FREEZING_COMMANDS = [
        'rank:monthly-run',
        'gbb:monthly-run',
        'fortune:enroll-eligible',
        'fortune:monthly-run',
        'adc:monthly-run',
        'offers:monthly-run',
        'compensation:monthly-close',
        'compensation:monthly-payout-close',
    ];

    /** True while the month has not ended in Asia/Kolkata (honours Carbon::setTestNow). */
    public static function isOpen(Carbon $month): bool
    {
        return Carbon::now('Asia/Kolkata')->lte($month->copy()->timezone('Asia/Kolkata')->endOfMonth());
    }

    /** The refusal to print, or null when the month has closed. */
    public static function refusal(Carbon $month): ?string
    {
        if (! self::isOpen($month)) {
            return null;
        }

        return sprintf(
            "%s has not closed yet (it ends %s IST). A monthly engine freezes the month's pool and roster the first time it runs and credits from that snapshot; a run now would price the month on partial BV, and the 1st-of-month run would keep that pricing because money had already moved on it. Wait for the month to close, or pass --%s only for a deliberate, provisional test freeze.",
            $month->format('F Y'),
            $month->copy()->endOfMonth()->format('d M Y 23:59'),
            self::OPTION,
        );
    }

    /**
     * `--in-flight` for a freezing command targeting an open month, empty otherwise.
     *
     * @return array<string, bool>
     */
    public static function overrideFor(string $commandSignature, Carbon $month): array
    {
        return in_array($commandSignature, self::FREEZING_COMMANDS, true) && self::isOpen($month)
            ? ['--'.self::OPTION => true]
            : [];
    }
}
