<?php

declare(strict_types=1);

namespace App\Modules\Returns\Services;

use InvalidArgumentException;

/**
 * The T&C §8 buy-back / cooling-off refund matrix — the single source of truth
 * for "how much do we refund, and is GST refunded" given a return reason and
 * the saleability of the returned goods.
 *
 * Pure and **total**: every (reason, saleable) pair returns an explicit policy.
 * Where §8 grants no refund the policy is `eligible:false` — never an undefined
 * fall-through. `refund_gst`/`invoice` decides whether a GST credit note or a
 * non-tax buyback voucher is issued downstream.
 *
 * Compliance-approved against `.claude/skills/arovolife-compliance-rules` §8
 * (ADR-0009). Timing (within the return window) is enforced by the caller using
 * `window_days`; this matrix only encodes the refund *treatment*.
 */
final class BuybackMatrix
{
    public const VERSION = 'v1';

    public const REASONS = [
        'cooling_off',
        'damage',
        'dissatisfaction',
        'general_buyback',
        'termination_buyback',
    ];

    /**
     * The refund policy for a (reason, saleable) pair.
     *
     * @return array{eligible: bool, refund_gst: bool, invoice: bool, window_days: int|null}
     */
    public function policy(string $reason, bool $saleable): array
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Unknown buyback reason '{$reason}'.");
        }

        // ineligible: §8 grants no refund for this row.
        $no = ['eligible' => false, 'refund_gst' => false, 'invoice' => false, 'window_days' => null];

        return match (true) {
            // Cooling-off: saleable only, 30 days, FULL refund incl. GST (credit note).
            $reason === 'cooling_off' && $saleable => ['eligible' => true, 'refund_gst' => true, 'invoice' => true, 'window_days' => 30],
            $reason === 'cooling_off' => $no, // non-saleable cooling-off: not eligible.

            // Damage: 10-day window. Saleable → full DS Price (credit note);
            // non-saleable → DS Price less GST (voucher).
            $reason === 'damage' && $saleable => ['eligible' => true, 'refund_gst' => true, 'invoice' => true, 'window_days' => 10],
            $reason === 'damage' => ['eligible' => true, 'refund_gst' => false, 'invoice' => false, 'window_days' => 10],

            // Dissatisfaction: 30-day window. Same saleable/non-saleable split.
            $reason === 'dissatisfaction' && $saleable => ['eligible' => true, 'refund_gst' => true, 'invoice' => true, 'window_days' => 30],
            $reason === 'dissatisfaction' => ['eligible' => true, 'refund_gst' => false, 'invoice' => false, 'window_days' => 30],

            // Only general_buyback / termination_buyback remain here: saleable
            // only, no window, DS Price less GST (voucher); non-saleable → not eligible.
            $saleable => ['eligible' => true, 'refund_gst' => false, 'invoice' => false, 'window_days' => null],
            default => $no,
        };
    }

    /**
     * The net refund in paise for the given line amounts, per the policy.
     * `dsPriceExGstPaise` is the pre-GST (taxable) Direct Seller Price for the
     * returned quantity; `gstPaise` the GST on it. Returns 0 when ineligible.
     */
    public function refundPaise(string $reason, bool $saleable, int $dsPriceExGstPaise, int $gstPaise): int
    {
        if ($dsPriceExGstPaise < 0 || $gstPaise < 0) {
            throw new InvalidArgumentException('Amounts must be non-negative.');
        }

        $policy = $this->policy($reason, $saleable);
        if (! $policy['eligible']) {
            return 0;
        }

        return $dsPriceExGstPaise + ($policy['refund_gst'] ? $gstPaise : 0);
    }

    /** True when the return is within the matrix window for its reason. */
    public function withinWindow(string $reason, bool $saleable, int $daysSinceDelivered): bool
    {
        $window = $this->policy($reason, $saleable)['window_days'];

        return $window === null || $daysSinceDelivered <= $window;
    }
}
