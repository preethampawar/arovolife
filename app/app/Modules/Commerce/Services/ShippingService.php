<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for order shipping charges.
 *
 * The fee and the free-shipping threshold are admin-configurable (stored in
 * `settings` in whole rupees for a clean admin UX). The rule is intentionally
 * simple: a cart at or above the threshold ships free; below it pays the flat
 * fee. Every surface that needs a shipping number — the cart, the checkout
 * summary and {@see CheckoutService::place()} — MUST call this service so the
 * figure can never diverge between display and the persisted order.
 */
final class ShippingService
{
    private const DEFAULT_FEE_RUPEES = 60;

    private const DEFAULT_FREE_THRESHOLD_RUPEES = 4000;

    /**
     * Shipping charge (in paise) for a cart whose merchandise value (before any
     * coupon) is $subtotalPaise. Returns 0 once the free-shipping threshold is met.
     */
    public function feePaise(int $subtotalPaise): int
    {
        if ($subtotalPaise >= $this->freeThresholdPaise()) {
            return 0;
        }

        return $this->settingRupeesToPaise('commerce.shipping.fee_rupees', self::DEFAULT_FEE_RUPEES);
    }

    /** The cart value (in paise) at or above which shipping is free. */
    public function freeThresholdPaise(): int
    {
        return $this->settingRupeesToPaise('commerce.shipping.free_threshold_rupees', self::DEFAULT_FREE_THRESHOLD_RUPEES);
    }

    /**
     * How much more merchandise (in paise) the cart needs to qualify for free
     * shipping, or 0 if it already qualifies. Used for the "add ₹X for free
     * shipping" nudge.
     */
    public function amountToFreeShippingPaise(int $subtotalPaise): int
    {
        return max(0, $this->freeThresholdPaise() - $subtotalPaise);
    }

    private function settingRupeesToPaise(string $key, int $defaultRupees): int
    {
        $raw = DB::table('settings')->where('key', $key)->value('value');
        $rupees = is_numeric($raw) ? (int) $raw : $defaultRupees;

        return max(0, $rupees) * 100;
    }
}
