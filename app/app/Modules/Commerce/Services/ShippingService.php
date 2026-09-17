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
 *
 * A collection order is charged {@see collectionFeePaise()} INSTEAD of the
 * delivery fee, never as well as it, and the free-shipping threshold does not
 * apply to it. Charging a buyer who collects for a delivery that never happens
 * is what R-94 was: a fee for a service not rendered.
 */
final class ShippingService
{
    private const DEFAULT_FEE_RUPEES = 60;

    private const DEFAULT_FREE_THRESHOLD_RUPEES = 4000;

    /** Collection is free at launch. The client can price it without a deploy. */
    private const DEFAULT_COLLECTION_FEE_RUPEES = 0;

    /**
     * Pincode ranges outside mainland India, as [first, last] inclusive.
     *
     * The Andaman & Nicobar Islands hold the whole 744xxx series; Lakshadweep
     * sits inside Kerala's 682xxx series on 682551-682559 (Kavaratti and the
     * other inhabited islands), so the range — not the prefix — is the test.
     *
     * @var array<int, array{int, int}>
     */
    private const NON_MAINLAND_RANGES = [
        [744001, 744999], // Andaman & Nicobar Islands
        [682551, 682559], // Lakshadweep
    ];

    /**
     * Delivery charge (in paise) for a cart whose merchandise value (before any
     * coupon) is $subtotalPaise. Returns 0 once the free-shipping threshold is met.
     *
     * DELIVERY ONLY. A collection order must not be charged this — see
     * {@see feeForOrderPaise()}, which is what checkout should call.
     */
    public function feePaise(int $subtotalPaise): int
    {
        if ($subtotalPaise >= $this->freeThresholdPaise()) {
            return 0;
        }

        return $this->settingRupeesToPaise('commerce.shipping.fee_rupees', self::DEFAULT_FEE_RUPEES);
    }

    /**
     * What this cart is actually charged for fulfilment, given how the buyer
     * chose to receive it. The one method checkout, the cart and the summary
     * should call, so a collection can never be quoted a delivery fee.
     *
     * @return array{shipping: int, collection: int}
     */
    public function feeForOrderPaise(int $subtotalPaise, bool $isCollection): array
    {
        return $isCollection
            ? ['shipping' => 0, 'collection' => $this->collectionFeePaise()]
            : ['shipping' => $this->feePaise($subtotalPaise), 'collection' => 0];
    }

    /**
     * The handling charge for collecting from an Arete Development Centre.
     *
     * Deliberately not subject to the free-shipping threshold: that threshold
     * buys off a delivery cost, and there is no delivery here. A collection fee
     * of ₹0 — the launch position — makes this moot, but the rule has to be
     * right before the lever is ever moved.
     */
    public function collectionFeePaise(): int
    {
        return $this->settingRupeesToPaise('commerce.collection_fee_rupees', self::DEFAULT_COLLECTION_FEE_RUPEES);
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

    /**
     * True when the admin restricts delivery to mainland India
     * (`commerce.shipping.india_mainland_only`). Defaults to ON, matching the
     * seeded value — a missing row must not silently open the islands.
     */
    public function mainlandOnly(): bool
    {
        return DB::table('settings')
            ->where('key', 'commerce.shipping.india_mainland_only')
            ->value('value') !== 'false';
    }

    /**
     * True when a delivery address with this pincode can be served under the
     * current setting. A malformed pincode is left to the format rule, so it
     * passes here rather than producing two errors for one field.
     */
    public function servesPincode(string $pincode): bool
    {
        if (preg_match('/^\d{6}$/', $pincode) !== 1 || ! $this->mainlandOnly()) {
            return true;
        }

        $code = (int) $pincode;

        foreach (self::NON_MAINLAND_RANGES as [$first, $last]) {
            if ($code >= $first && $code <= $last) {
                return false;
            }
        }

        return true;
    }

    private function settingRupeesToPaise(string $key, int $defaultRupees): int
    {
        $raw = DB::table('settings')->where('key', $key)->value('value');
        $rupees = is_numeric($raw) ? (int) $raw : $defaultRupees;

        return max(0, $rupees) * 100;
    }
}
