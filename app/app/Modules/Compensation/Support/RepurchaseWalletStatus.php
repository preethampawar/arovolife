<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;

/**
 * Traffic-light urgency for clearing the repurchase wallet before the
 * distributor's own repurchase deadline.
 *
 * The wallet has to stand at ₹0 on ONE date: the last day of the
 * distributor's own 30-day repurchase window (client 2026-09-07, condition B)
 * — a distributor anchored on the 10th is judged on the 8th of the following
 * month (start + 29), whatever the calendar month does. There is no month-end
 * wallet date (user decision 2026-10-17 retired it).
 *
 * Callers pass the cycle's due date. With no cycle (none started yet, or the
 * repurchase engine off) there is no date to count down to, so the reminder
 * shows the balance without a countdown.
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
        public ?int $daysRemaining,
        public ?Carbon $deadline,
        public int $balancePaise,
    ) {}

    /**
     * @param  Carbon|null  $deadline  the distributor's cycle due date; null when
     *                                 they have no cycle
     */
    public static function for(int $balancePaise, ?Carbon $today = null, ?Carbon $deadline = null): self
    {
        $today ??= Carbon::today('Asia/Kolkata');

        if ($deadline === null) {
            return new self(
                tone: $balancePaise <= 0 ? 'cleared' : 'green',
                daysRemaining: null,
                deadline: null,
                balancePaise: $balancePaise,
            );
        }

        $deadline = $deadline->copy()->startOfDay();

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

        if ($this->deadline === null) {
            // No cycle: nothing judges the balance yet, so state no deadline.
            return 'Spend this at checkout on your own purchases.';
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
