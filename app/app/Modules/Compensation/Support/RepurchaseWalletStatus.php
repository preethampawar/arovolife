<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;

/**
 * Traffic-light urgency for clearing the repurchase wallet before month end.
 *
 * The monthly bonus gates read the balance frozen by
 * `compensation:repurchase-snapshot` on the 1st, so the deadline that actually
 * matters to a distributor is the last day of the current calendar month —
 * hence the tone escalates by calendar day, not by any income figure.
 *
 * The day thresholds are presentation constants, not plan economics: they only
 * decide when the reminder turns amber or red and are deliberately NOT stored
 * in `compensation_plan_settings`, which holds money parameters.
 *
 * Hard rule 3: this never states or implies future earnings. It restates an
 * existing plan condition ("the wallet must be ₹0 at month end") against a
 * balance the distributor already holds.
 */
final readonly class RepurchaseWalletStatus
{
    private const AMBER_FROM_DAY = 11;

    private const RED_FROM_DAY = 21;

    /**
     * @param  string  $tone  'cleared'|'green'|'amber'|'red'
     */
    private function __construct(
        public string $tone,
        public int $daysRemaining,
        public Carbon $deadline,
        public int $balancePaise,
    ) {}

    public static function for(int $balancePaise, ?Carbon $today = null): self
    {
        $today ??= Carbon::today('Asia/Kolkata');
        $deadline = $today->copy()->endOfMonth()->startOfDay();

        $tone = match (true) {
            $balancePaise <= 0 => 'cleared',
            $today->day >= self::RED_FROM_DAY => 'red',
            $today->day >= self::AMBER_FROM_DAY => 'amber',
            default => 'green',
        };

        return new self(
            tone: $tone,
            daysRemaining: (int) $today->diffInDays($deadline) + 1,
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
            return 'Nothing to clear this month.';
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
