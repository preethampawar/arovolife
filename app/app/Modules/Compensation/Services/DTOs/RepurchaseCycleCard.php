<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use Illuminate\Support\Carbon;

/**
 * Everything the repurchase status card shows, resolved once.
 *
 * A read-only presenter: it is assembled from {@see RepurchaseCycleService}
 * and never writes. The point of collecting it here rather than querying from
 * Blade is that the card shows money and deadlines — the figures a distributor
 * plans a purchase around — and those deserve a value a test can assert
 * against rather than a view that has to be scraped.
 *
 * Money is carried in paise throughout; the view formats it.
 *
 * @see docs/plans/repurchase-cycle-visual-2026-09-13.md
 */
final readonly class RepurchaseCycleCard
{
    /** No obligation yet — the distributor has not reached the BV gate. */
    public const STATE_NOT_QUALIFIED = 'not_qualified';

    /** Inside the window, obligation not yet met. */
    public const STATE_ACTIVE = 'active';

    /** Obligation met for this cycle. */
    public const STATE_COMPLETED = 'completed';

    /** The window closed unmet. */
    public const STATE_SUSPENDED = 'suspended';

    public function __construct(
        public string $state,
        public ?Carbon $startDate,
        public ?Carbon $endDate,
        public int $daysLeft,
        public int $daysTotal,
        public int $requiredBvPaise,
        public int $completedBvPaise,
        public int $walletBalancePaise,
        public bool $walletZeroed,
        public ?string $failureReason,
        public int $personalBvPaise,
        public int $qualifyBvPaise,
    ) {}

    /**
     * The distributor has not reached the BV gate, so no cycle exists yet.
     * `personalBvPaise` / `qualifyBvPaise` carry their progress toward it.
     */
    public static function notQualified(int $personalBvPaise, int $qualifyBvPaise): self
    {
        return new self(
            state: self::STATE_NOT_QUALIFIED,
            startDate: null,
            endDate: null,
            daysLeft: 0,
            daysTotal: 0,
            requiredBvPaise: 0,
            completedBvPaise: 0,
            walletBalancePaise: 0,
            walletZeroed: false,
            failureReason: null,
            personalBvPaise: $personalBvPaise,
            qualifyBvPaise: $qualifyBvPaise,
        );
    }

    /**
     * @param  int|null  $liveWalletBalancePaise  The repurchase wallet as it stands
     *                                            now, for a window that has not resolved yet. The frozen column is
     *                                            NULL until the window's last day, so without this the card would
     *                                            report "now ₹0.00" to a distributor who is still holding money.
     */
    public static function fromCycle(RepurchaseCycle $cycle, Carbon $today, int $personalBvPaise, int $qualifyBvPaise, ?int $liveWalletBalancePaise = null): self
    {
        $start = $cycle->cycle_start_date->copy()->startOfDay();
        $end = $cycle->due_date->copy()->startOfDay();

        // Inclusive of the due date: a distributor still has the whole of the
        // last day to fulfil, so a window ending today reads "1 day left", not
        // zero. diffInDays would report 0 and imply the chance had passed.
        $daysTotal = (int) $start->diffInDays($end) + 1;
        $daysLeft = (int) max(0, $today->copy()->startOfDay()->diffInDays($end, false) + 1);

        return new self(
            state: match ($cycle->status) {
                RepurchaseCycle::STATUS_COMPLETED => self::STATE_COMPLETED,
                RepurchaseCycle::STATUS_SUSPENDED => self::STATE_SUSPENDED,
                default => self::STATE_ACTIVE,
            },
            startDate: $start,
            endDate: $end,
            daysLeft: $daysLeft,
            daysTotal: max(1, $daysTotal),
            requiredBvPaise: (int) $cycle->required_bv_paise,
            completedBvPaise: (int) $cycle->completed_bv_paise,
            walletBalancePaise: (int) ($cycle->wallet_balance_paise ?? $liveWalletBalancePaise ?? 0),
            walletZeroed: (bool) ($cycle->wallet_zeroed ?? false),
            failureReason: $cycle->failure_reason,
            personalBvPaise: $personalBvPaise,
            qualifyBvPaise: $qualifyBvPaise,
        );
    }

    public function qualified(): bool
    {
        return $this->state !== self::STATE_NOT_QUALIFIED;
    }

    /** Share of the window still to run, for the ring. */
    public function ringFraction(): float
    {
        if ($this->daysTotal <= 0) {
            return 0.0;
        }

        return min(1.0, max(0.0, $this->daysLeft / $this->daysTotal));
    }

    /** Share of this cycle's BV obligation already purchased. */
    public function bvFraction(): float
    {
        if ($this->requiredBvPaise <= 0) {
            return 1.0;
        }

        return min(1.0, max(0.0, $this->completedBvPaise / $this->requiredBvPaise));
    }

    /** Share of the qualification gate already reached. */
    public function qualifyFraction(): float
    {
        if ($this->qualifyBvPaise <= 0) {
            return 1.0;
        }

        return min(1.0, max(0.0, $this->personalBvPaise / $this->qualifyBvPaise));
    }

    public function bvMet(): bool
    {
        return $this->completedBvPaise >= $this->requiredBvPaise;
    }

    public function bvRemainingPaise(): int
    {
        return max(0, $this->requiredBvPaise - $this->completedBvPaise);
    }

    public function qualifyRemainingPaise(): int
    {
        return max(0, $this->qualifyBvPaise - $this->personalBvPaise);
    }

    /** Both conditions are what the engine actually checks at window end. */
    public function bothConditionsMet(): bool
    {
        return $this->bvMet() && $this->walletZeroed;
    }

    public function urgent(): bool
    {
        return $this->state === self::STATE_ACTIVE && $this->daysLeft <= 7;
    }

    /**
     * How far into the window the distributor is, as a traffic light.
     *
     * Thirds of the window rather than hardcoded day counts, because the
     * window length is the DB-driven comp.repurchase.cycle_days: on the
     * default 30-day window that is green to day 10, amber to day 20, red
     * from day 21 — and it still reads correctly if the setting changes.
     */
    public function urgencyTier(): string
    {
        $window = max(1, $this->daysTotal - 1);
        $elapsed = max(0, $this->daysTotal - $this->daysLeft);

        return match (true) {
            $elapsed <= $window / 3 => 'ok',
            $elapsed <= $window * 2 / 3 => 'warn',
            default => 'late',
        };
    }

    /** Plain-language reason a closed window was not met. */
    public function failureLabel(): ?string
    {
        return match ($this->failureReason) {
            RepurchaseCycle::REASON_BV_SHORT => 'The repurchase BV for the window was not reached.',
            RepurchaseCycle::REASON_WALLET_NONZERO => 'The repurchase wallet was not ₹0 on the last day of the window.',
            RepurchaseCycle::REASON_BOTH => 'The repurchase BV was not reached and the repurchase wallet was not ₹0 on the last day.',
            default => null,
        };
    }
}
