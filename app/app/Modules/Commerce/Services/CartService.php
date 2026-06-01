<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Services\DTOs\CouponResult;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class CartService
{
    public function __construct(
        private readonly AttributionService $attribution,
        private readonly CouponService $coupons,
    ) {}

    public function currentCart(Request $request): Cart
    {
        $anonKey = $this->attribution->anonymousKey($request);
        $userId = $request->user()?->id;

        $customer = null;
        if ($userId !== null) {
            $customer = Customer::where('user_id', $userId)->first();
        }

        $cart = Cart::query()
            ->when($customer !== null, fn ($q) => $q->where('customer_id', $customer->id))
            ->when($customer === null, fn ($q) => $q->whereNull('customer_id')->where('anonymous_key', $anonKey))
            ->where('expires_at', '>', now())
            ->first();

        if ($cart !== null) {
            return $cart;
        }

        return Cart::create([
            'customer_id' => $customer?->id,
            'anonymous_key' => $anonKey,
            'ref_adn_snapshot' => $request->cookie(AttributionService::COOKIE_NAME),
            'expires_at' => Carbon::now()->addDays(7),
        ]);
    }

    public function addItem(Cart $cart, int $variantId, int $qty = 1): CartItem
    {
        $variant = ProductVariant::with('product')->findOrFail($variantId);

        $existing = $cart->items()->where('product_variant_id', $variantId)->first();
        if ($existing !== null) {
            $existing->qty += $qty;
            $existing->save();

            return $existing;
        }

        return CartItem::create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'qty' => $qty,
            'unit_price_paise' => $variant->sale_price_paise,
            'bv_paise' => $variant->bv_paise,
            'pv_paise' => $variant->pv_paise,
            'gst_rate_bp' => $variant->gst_rate_bp,
        ]);
    }

    public function updateQty(CartItem $item, int $qty): void
    {
        if ($qty <= 0) {
            $item->delete();

            return;
        }
        $item->qty = $qty;
        $item->save();
    }

    public function remove(CartItem $item): void
    {
        $item->delete();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
    }

    /**
     * Validate a promo code against the cart and, if valid, attach it.
     * Returns the {@see CouponResult} so the caller can surface success or the
     * customer-safe error message.
     */
    public function applyCoupon(Cart $cart, string $code, ?Customer $customer): CouponResult
    {
        $result = $this->coupons->validate($code, $cart, $customer);

        if ($result->ok && $result->coupon !== null) {
            $cart->coupon_id = $result->coupon->id;
            $cart->save();
        }

        return $result;
    }

    public function removeCoupon(Cart $cart): void
    {
        $cart->coupon_id = null;
        $cart->save();
    }
}
