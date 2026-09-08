<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;

/**
 * Traffic-light urgency for clearing the repurchase wallet before the
 * distributor's own repurchase deadline.
 *
 * The wallet has to stand at ₹0 on TWO dates, and the reminder counts down to
 * whichever comes first:
 *
 *  - the last day of the distributor's own 30-day repurchase window (client
 *    2026-09-07, condition B) — a distributor anchored on the 10th is judged
 *    on the 9th of the following month, not on the 31st;
 *  - the last instant of every calendar month, which is what the monthly
 *    bonus engines (Growth Booster, Fortune, rank requalification, AO-GO)
 *    read through RepurchaseWalletGateService::clearedAtMonthEnd().
 *
 * Callers pass the cycle's due date; with no cycle yet only the month-end
 * condition exists, so the deadline is the end of the current month.
 *
 * Once the window's last day has passed with a balance still standing, the
 * cycle has FAILED (forfeit model): every further day until the wallet is ₹0
 * is a day whose Genos BV is not counted. The reminder says so instead of
 * counting down to a date that is already behind them.
 *
 * The escalation thresholds are presentation constants, not plan economics —
 * they only decide when the reminder turns amber or red, and are deliberately
 * NOT stored in `compensation_plan_settings`, which holds money parameters.
 * They are expressed in DAYS REMAINING rather than day-of-month, because a
 * rolling window has no month boundary to count from.
 *
 * Hard rule 3: this never states or implies future earnings. It restates an
 * existing plan condition ("the wallet must be ₹0 by your cycle's last day")
 * against a balance the distributor already holds.
 */
final readonly class RepurchaseWalletStatus
{
    /** 20 or fewer days left in the window. */
    private const AMBER_WITHIN_DAYS = 20;

    /** 10 or fewer days left in the window. */
    private const RED_WITHIN_DAYS = 10;

    /**
     * @param  string  $tone  'cleared'|'green'|'amber'|'red'
     */
    private function __construct(
        public string $tone,
        public int $daysRemaining,
        public Carbon $deadline,
        public int $balancePaise,
    ) {}

    /**
     * @param  Carbon|null  $deadline  the distributor's cycle due date; falls back
     *                                 to the end of the current month when they
     *                                 have no cycle yet
     */
    public static function for(int $balancePaise, ?Carbon $today = null, ?Carbon $deadline = null): self
    {
        $today ??= Carbon::today('Asia/Kolkata');
        $monthEnd = $today->copy()->endOfMonth()->startOfDay();
        $deadline = $deadline?->copy()->startOfDay() ?? $monthEnd;

        // A window still running past the month end is judged at the month end
        // first; a window that has already closed keeps its own date so the
        // reminder can say it was missed.
        if ($deadline->greaterThan($monthEnd)) {
            $deadline = $monthEnd;
        }

        // 0 = the deadline has passed: the window closed with a balance, so the
        // distributor is in a failed cycle until the wallet is ₹0.
        $daysRemaining = max(0, (int) $today->diffInDays($deadline, absolute: false) + 1);

        $tone = match (true) {
            $balancePaise <= 0 => 'cleared',
            $daysRemaining <= self::RED_WITHIN_DAYS => 'red',
            $daysRemaining <= self::AMBER_WITHIN_DAYS => 'amber',
            default => 'green',
        };

        return new self(
            tone: $tone,
            daysRemaining: $daysRemaining,
            deadline: $deadline,
            balancePaise: $balancePaise,
        );
    }

    public function label(): string
    {
        return match ($this->tone) {
            'cleared' => 'Cleared — ₹0',
            'amber' => 'Clear soon',
            'red' => 'Clear now',
            default => 'On track',
        };
    }

    /** The window's last day has passed with a balance still standing. */
    public function overdue(): bool
    {
        return $this->tone !== 'cleared' && $this->daysRemaining === 0;
    }

    public function detail(): string
    {
        if ($this->tone === 'cleared') {
            return 'Nothing to clear this cycle.';
        }

        if ($this->overdue()) {
            return sprintf(
                'Your repurchase window closed on %s with a balance — your Genos BV for each day until this is ₹0 is not counted.',
                $this->deadline->format('d M'),
            );
        }

        return sprintf(
            'Bring this to ₹0 by %s — %d day%s left',
            $this->deadline->format('d M'),
            $this->daysRemaining,
            $this->daysRemaining === 1 ? '' : 's',
        );
    }

    public function pillClasses(): string
    {
        return match ($this->tone) {
            'cleared' => 'bg-gray-100 text-gray-600',
            'amber' => 'bg-amber-100 text-amber-700',
            'red' => 'bg-red-100 text-red-700',
            default => 'bg-green-100 text-green-700',
        };
    }
}
