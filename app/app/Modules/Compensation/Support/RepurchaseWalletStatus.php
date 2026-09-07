<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;

/**
 * Traffic-light urgency for clearing the repurchase wallet before the
 * distributor's own repurchase deadline.
 *
 * The deadline is the last day of THEIR cycle (client 2026-09-06 rule 4B), not
 * the last day of the calendar month: a distributor anchored on the 10th is
 * judged on the 8th of the following month, and telling them "by the 31st"
 * would be telling them the wrong date. Callers pass the cycle's due date; with
 * no cycle yet there is no obligation to be urgent about, and the fallback is
 * the end of the current month.
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
        $deadline = ($deadline ?? $today->copy()->endOfMonth())->copy()->startOfDay();

        // A deadline already past still reads as "today" rather than a negative
        // count: the cycle is being resolved, and the distributor's action —
        // clear the wallet — has not changed.
        $daysRemaining = max(1, (int) $today->diffInDays($deadline, absolute: false) + 1);

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

    public function detail(): string
    {
        if ($this->tone === 'cleared') {
            return 'Nothing to clear this cycle.';
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
