<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\Coupon;
use App\Modules\Commerce\Models\CouponRedemption;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Services\DTOs\CouponResult;

/**
 * Validates promo codes against a cart and computes the rupee discount.
 *
 * A discount NEVER produces commission or income — it only reduces what the
 * customer pays. Compliance hard rules #2/#3 are unaffected: BV on order lines
 * is the SKU's documented BV and is independent of any coupon.
 */
final class CouponService
{
    /**
     * Validate a code against the cart (and customer, when known). Returns a
     * CouponResult carrying the matched coupon + computed discount, or a
     * customer-safe error message.
     */
    public function validate(string $code, Cart $cart, ?Customer $customer = null, bool $lockForUpdate = false): CouponResult
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return CouponResult::fail('Enter a promo code.');
        }

        // When called from inside the checkout transaction ($lockForUpdate),
        // take a row lock on the coupon so two concurrent checkouts cannot both
        // read used_count below the limit and both redeem — which would breach
        // a usage_limit and create a discount with no clean product-sale trail
        // (compliance R-01). The cart-view re-validation path passes false.
        $query = Coupon::query()->where('code', $code);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $coupon = $query->first();
        if ($coupon === null || ! $coupon->isActive()) {
            return CouponResult::fail('That promo code is not valid.');
        }

        if (! $coupon->isWithinWindow()) {
            return CouponResult::fail('This promo code is not active right now.');
        }

        if (! $coupon->hasUsesLeft()) {
            return CouponResult::fail('This promo code has reached its usage limit.');
        }

        $subtotal = $cart->subtotalPaise();
        if ($subtotal < $coupon->min_purchase_paise) {
            $needed = number_format(($coupon->min_purchase_paise - $subtotal) / 100, 2);

            return CouponResult::fail("Add ₹{$needed} more to use this code.");
        }

        if ($customer !== null && $coupon->per_customer_limit !== null) {
            $used = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('customer_id', $customer->id)
                ->count();
            if ($used >= $coupon->per_customer_limit) {
                return CouponResult::fail('You have already used this promo code.');
            }
        }

        $discount = $this->discountFor($coupon, $cart);
        if ($discount <= 0) {
            return CouponResult::fail('This code does not apply to the items in your cart.');
        }

        return CouponResult::ok($coupon, $discount);
    }

    /**
     * Compute the discount (paise) a coupon yields on a cart, honouring scope,
     * type and the percent cap. Never exceeds the eligible base or the subtotal.
     */
    public function discountFor(Coupon $coupon, Cart $cart): int
    {
        $cart->loadMissing('items.variant.product');

        $eligibleBase = (int) $cart->items->sum(function ($item) use ($coupon): int {
            $line = $item->qty * $item->unit_price_paise;

            return match ($coupon->scope) {
                Coupon::SCOPE_CATEGORY => ((int) $item->variant?->product?->category_id === (int) $coupon->scope_id) ? $line : 0,
                Coupon::SCOPE_PRODUCT => ((int) $item->variant?->product?->id === (int) $coupon->scope_id) ? $line : 0,
                default => $line,
            };
        });

        if ($eligibleBase <= 0) {
            return 0;
        }

        $raw = $coupon->type === Coupon::TYPE_PERCENT
            ? intdiv($eligibleBase * $coupon->value, 100)
            : $coupon->value;

        if ($coupon->type === Coupon::TYPE_PERCENT && $coupon->max_discount_paise !== null) {
            $raw = min($raw, $coupon->max_discount_paise);
        }

        // Never discount more than the eligible items, nor more than the cart.
        return max(0, min($raw, $eligibleBase, $cart->subtotalPaise()));
    }

    /**
     * Record a redemption and increment the coupon's used counter.
     */
    public function recordRedemption(Coupon $coupon, ?int $orderId, ?int $customerId, int $discountPaise): void
    {
        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'discount_paise' => $discountPaise,
        ]);

        $coupon->increment('used_count');
    }
}
