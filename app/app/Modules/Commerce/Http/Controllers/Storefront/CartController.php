<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\SharedCart;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CouponService;
use App\Modules\Commerce\Services\ShippingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CouponService $coupons,
        private readonly ShippingService $shipping,
        private readonly AttributionService $attribution,
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

        $subtotalPaise = $cart->subtotalPaise();

        return view('shop.cart', [
            'cart' => $cart,
            'couponDiscount' => $couponDiscount,
            'shippingPaise' => $this->shipping->feePaise($subtotalPaise),
            'amountToFreeShippingPaise' => $this->shipping->amountToFreeShippingPaise($subtotalPaise),
        ]);
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

    public function add(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $cart = $this->cartService->currentCart($request);
        $variantId = (int) $validated['product_variant_id'];
        $this->cartService->addItem($cart, $variantId, (int) ($validated['qty'] ?? 1));

        // AJAX add (from a listing card): stay on the page — return the new cart
        // count + message for the toast instead of redirecting to the cart.
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'count' => (int) $cart->items()->sum('qty'),
                'message' => 'Product successfully added to cart.',
            ]);
        }

        // Flash the just-added variant so the cart page can highlight that line.
        return redirect()->route('shop.cart')
            ->with('status', 'Added to cart.')
            ->with('added_variant_id', $variantId);
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

    /**
     * Snapshot the current cart into a shareable "Easy Purchase" link. Only a
     * logged-in distributor can share (the link credits them, mirroring the
     * single-product ?ref share); no income is shown or implied.
     */
    public function share(Request $request): RedirectResponse
    {
        $adn = $request->user()?->distributor?->adn;
        if ($adn === null) {
            return redirect()->route('shop.cart')
                ->withErrors(['share' => 'Only distributors can create a share link.']);
        }

        $cart = $this->cartService->currentCart($request);

        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->get()
            ->map(fn (CartItem $i): array => ['variant_id' => $i->product_variant_id, 'qty' => $i->qty])
            ->values()
            ->all();

        if ($items === []) {
            return redirect()->route('shop.cart')
                ->withErrors(['share' => 'Add at least one product before sharing.']);
        }

        $shared = SharedCart::create([
            'code' => $this->uniqueShareCode(),
            'distributor_id' => $request->user()->distributor->id,
            'ref_adn' => $adn,
            'created_by_user_id' => $request->user()->id,
            'items' => $items,
            'expires_at' => now()->addDays(30),
        ]);

        return redirect()->route('shop.cart')
            ->with('shared_cart_url', $shared->url());
    }

    /**
     * Open a shared cart: credit the sharer (attribution cookie) and load the
     * snapshot into the visitor's own cart, re-pricing each line from the live
     * variant. Inactive / removed products are skipped silently.
     */
    public function openShared(Request $request, string $code): RedirectResponse
    {
        $shared = SharedCart::where('code', $code)->first();

        if ($shared === null || $shared->isExpired()) {
            return redirect()->route('shop.index')
                ->withErrors(['share' => 'This share link is invalid or has expired.']);
        }

        // Credit the sharing distributor (same 30-day attribution as ?ref).
        if (is_string($shared->ref_adn) && $shared->ref_adn !== '') {
            $this->attribution->recordTouch($request, $shared->ref_adn);
        }

        // Mark this session as having arrived via a valid shared cart. This is
        // the guest's pass through the members-only checkout gate (scoped: only
        // shared-link recipients may guest-checkout) and names the distributor
        // whose read-only details the customer sees at checkout.
        if ($shared->distributor_id !== null) {
            $request->session()->put(SharedCart::SESSION_DISTRIBUTOR_KEY, $shared->distributor_id);
        }

        $cart = $this->cartService->currentCart($request);

        $added = 0;
        foreach ($shared->items as $line) {
            $variantId = (int) ($line['variant_id'] ?? 0);
            $qty = max(1, min(10, (int) ($line['qty'] ?? 1)));
            if ($variantId <= 0) {
                continue;
            }

            $variant = ProductVariant::where('id', $variantId)->where('status', 'active')->first();
            if ($variant === null) {
                continue; // product archived / variant pulled since the link was made
            }

            $this->cartService->addItem($cart, $variantId, $qty);
            $added++;
        }

        if ($added === 0) {
            return redirect()->route('shop.index')
                ->withErrors(['share' => 'None of the shared products are available right now.']);
        }

        return redirect()->route('shop.cart')
            ->with('status', $added === 1 ? '1 product added from a shared cart.' : "{$added} products added from a shared cart.");
    }

    private function uniqueShareCode(): string
    {
        do {
            $code = Str::upper(Str::random(10));
        } while (SharedCart::where('code', $code)->exists());

        return $code;
    }
}
