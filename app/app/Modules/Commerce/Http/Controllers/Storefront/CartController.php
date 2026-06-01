<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CouponService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CouponService $coupons,
    ) {}

    public function show(Request $request): View
    {
        $cart = $this->cartService->currentCart($request);
        $cart->load('items.variant.product', 'coupon');

        // Re-validate any attached coupon at view time so a now-invalid code
        // (expired, min no longer met, limit reached) is silently dropped
        // rather than shown as a discount the customer can't actually get.
        $couponDiscount = 0;
        if ($cart->coupon !== null) {
            $result = $this->coupons->validate($cart->coupon->code, $cart, $this->resolveCustomer($request));
            if ($result->ok) {
                $couponDiscount = $result->discountPaise;
            } else {
                $this->cartService->removeCoupon($cart);
                $cart->setRelation('coupon', null);
            }
        }

        return view('shop.cart', ['cart' => $cart, 'couponDiscount' => $couponDiscount]);
    }

    public function applyCoupon(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:40']]);

        $cart = $this->cartService->currentCart($request);
        $result = $this->cartService->applyCoupon($cart, $validated['code'], $this->resolveCustomer($request));

        if ($result->ok) {
            return redirect()->route('shop.cart')->with('status', 'Promo code applied.');
        }

        return redirect()->route('shop.cart')->withErrors(['code' => $result->error]);
    }

    public function removeCoupon(Request $request): RedirectResponse
    {
        $cart = $this->cartService->currentCart($request);
        $this->cartService->removeCoupon($cart);

        return redirect()->route('shop.cart')->with('status', 'Promo code removed.');
    }

    private function resolveCustomer(Request $request): ?Customer
    {
        $userId = $request->user()?->id;

        return $userId !== null ? Customer::where('user_id', $userId)->first() : null;
    }

    public function add(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $cart = $this->cartService->currentCart($request);
        $this->cartService->addItem($cart, (int) $validated['product_variant_id'], (int) ($validated['qty'] ?? 1));

        return redirect()->route('shop.cart')->with('status', 'Added to cart.');
    }

    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'qty' => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        $this->cartService->updateQty($item, (int) $validated['qty']);

        return redirect()->route('shop.cart');
    }

    public function remove(CartItem $item): RedirectResponse
    {
        $this->cartService->remove($item);

        return redirect()->route('shop.cart');
    }
}
