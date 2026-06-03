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
        $cart = $this->findCart($request);
        if ($cart !== null) {
            return $cart;
        }

        // No existing cart — mint the anon key (queues the cookie) and create
        // one. A logged-in visitor without a Customer row yet gets an
        // anonymous cart; the customer link is established at checkout.
        $userId = $request->user()?->id;
        $customer = $userId !== null ? Customer::where('user_id', $userId)->first() : null;

        return Cart::create([
            'customer_id' => $customer?->id,
            'anonymous_key' => $this->attribution->anonymousKey($request),
            'ref_adn_snapshot' => $request->cookie(AttributionService::COOKIE_NAME),
            'expires_at' => Carbon::now()->addDays(7),
        ]);
    }

    /**
     * Resolve the visitor's CURRENT cart without creating one — the single
     * source of truth for "which cart is this visitor's". Mirrors the lookup
     * in {@see self::currentCart()}: a logged-in visitor with a Customer row
     * is matched by customer_id; everyone else (including a logged-in visitor
     * who hasn't checked out yet, so has no Customer row) falls back to the
     * anonymous cookie key. Returns null when there's no cart.
     */
    public function findCart(Request $request): ?Cart
    {
        $userId = $request->user()?->id;
        $customer = $userId !== null ? Customer::where('user_id', $userId)->first() : null;

        $query = Cart::query()->where('expires_at', '>', now());

        if ($customer !== null) {
            $query->where('customer_id', $customer->id);
        } else {
            $anonKey = $request->cookie(AttributionService::ANON_COOKIE);
            if (! is_string($anonKey) || $anonKey === '') {
                return null;
            }
            $query->whereNull('customer_id')->where('anonymous_key', $anonKey);
        }

        return $query->first();
    }

    /**
     * Total quantity of items in the visitor's CURRENT cart, or 0 if none.
     * Read-only — never creates a cart (so it's safe to call from the nav on
     * every page). Used for the cart-icon count badge.
     */
    public function itemCount(Request $request): int
    {
        $cart = $this->findCart($request);

        return $cart === null ? 0 : (int) $cart->items()->sum('qty');
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
