<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Services\DTOs\CouponResult;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class CartService
{
    public function __construct(
        private readonly AttributionService $attribution,
        private readonly CouponService $coupons,
        private readonly StockLedger $stockLedger,
        private readonly OrderFulfilmentService $fulfilment,
    ) {}

    public function currentCart(Request $request): Cart
    {
        $cart = $this->findCart($request);
        if ($cart !== null) {
            // A guest builds the cart, then signs in to check out — the price
            // tier has to follow them, or the member is charged the price the
            // product page told them they would not pay (QA F55).
            $this->repriceForBuyer($cart, $request->user());

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

    public function addItem(Cart $cart, int $variantId, int $qty = 1, ?User $buyer = null): CartItem
    {
        $variant = ProductVariant::with('product')->findOrFail($variantId);

        $existing = $cart->items()->where('product_variant_id', $variantId)->first();
        if ($existing !== null) {
            $existing->qty = $this->clampToAvailable($variant, $existing->qty + $qty);
            $existing->save();

            return $existing;
        }

        return CartItem::create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'qty' => $this->clampToAvailable($variant, $qty),
            'unit_price_paise' => $this->unitPricePaise($variant, $buyer),
            'bv_paise' => $variant->bv_paise,
            'gst_rate_bp' => $variant->gst_rate_bp,
        ]);
    }

    /**
     * The unit price this buyer pays for this variant.
     *
     * A logged-in Direct Seller pays the distributor price wherever the
     * catalogue sets one below the sale price — the tier their product page
     * already shows them (client decision 2026-09-11, QA F55). Everyone else
     * pays the sale price. BV is unaffected: it is a property of the SKU, not
     * of the price paid.
     */
    public function unitPricePaise(ProductVariant $variant, ?User $buyer): int
    {
        return $variant->priceForTierPaise($buyer?->distributor !== null);
    }

    /**
     * Move every line of an existing cart onto the price tier this buyer is
     * entitled to, so what the cart charges is what the catalogue shows them.
     *
     * Deliberately narrow: a line is only ever flipped between the two known
     * catalogue tiers. A line whose snapshot matches neither — the catalogue
     * price moved after it was added — keeps the price the buyer was quoted.
     */
    public function repriceForBuyer(Cart $cart, ?User $buyer): void
    {
        $lines = CartItem::query()->where('cart_id', $cart->id)->get();
        if ($lines->isEmpty()) {
            return;
        }

        $variants = ProductVariant::query()
            ->whereIn('id', $lines->pluck('product_variant_id')->all())
            ->get()
            ->keyBy('id');

        foreach ($lines as $item) {
            $variant = $variants->get($item->product_variant_id);
            if ($variant === null || ! $variant->hasDistributorPrice()) {
                continue;
            }

            $tiers = [$variant->priceForTierPaise(false), $variant->priceForTierPaise(true)];
            $target = $this->unitPricePaise($variant, $buyer);
            $current = (int) $item->getAttribute('unit_price_paise');

            if ($current !== $target && in_array($current, $tiers, true)) {
                $item->setAttribute('unit_price_paise', $target);
                $item->save();
                $cart->unsetRelation('items');
            }
        }
    }

    public function updateQty(CartItem $item, int $qty): void
    {
        if ($qty <= 0) {
            $item->delete();

            return;
        }
        $item->qty = $this->fulfilment->availabilityEnforced() && $item->variant !== null
            ? $this->clampToAvailable($item->variant, $qty)
            : $qty;
        $item->save();
    }

    /**
     * Inventory plan H2 — non-blocking: while enforcement is on, a tracked
     * line is cut down to what is available and the buyer is told the plain
     * number. Nothing is refused here; checkout (H1) is the hard stop, which
     * is also what an out-of-stock line (nothing to clamp to) runs into.
     */
    private function clampToAvailable(ProductVariant $variant, int $qty): int
    {
        if ($variant->inventory_policy !== 'track' || ! $this->fulfilment->availabilityEnforced()) {
            return $qty;
        }

        $available = $this->stockLedger->available($variant->id);
        if ($available >= $qty) {
            return $qty;
        }

        $name = $variant->product->name ?? $variant->variant_sku;
        session()->flash('stock_notice', $available > 0
            ? "Only {$available} available of {$name}."
            : "{$name} is out of stock.");

        return $available > 0 ? $available : $qty;
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
