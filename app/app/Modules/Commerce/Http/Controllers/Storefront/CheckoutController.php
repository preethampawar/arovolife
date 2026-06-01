<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\CouponService;
use App\Modules\Payments\Services\StubGateway;
use App\Modules\Tax\Services\InvoiceGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly AttributionService $attribution,
        private readonly CheckoutService $checkoutService,
        private readonly CouponService $coupons,
        private readonly StubGateway $gateway,
        private readonly InvoiceGenerator $invoiceGenerator,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $this->ensureCheckoutEnabled();

        if ($redirect = $this->membersOnlyRedirect()) {
            return $redirect;
        }

        $cart = $this->cartService->currentCart($request);
        $cart->load('items.variant.product', 'coupon');

        if ($cart->items->isEmpty()) {
            abort(302, 'Cart empty', ['Location' => route('shop.index')]);
        }

        $couponDiscount = $cart->coupon !== null ? $this->coupons->discountFor($cart->coupon, $cart) : 0;

        $guestAllowed = DB::table('settings')
            ->where('key', 'commerce.guest_checkout.enabled')->value('value') === 'true';

        return view('shop.checkout', [
            'cart' => $cart,
            'couponDiscount' => $couponDiscount,
            'guestAllowed' => $guestAllowed,
            'onlineEnabled' => $this->onlineEnabled(),
            'codEnabled' => $this->flag('payments.cod.enabled'),
            'refAdn' => $request->cookie(AttributionService::COOKIE_NAME),
        ]);
    }

    public function place(Request $request): RedirectResponse
    {
        $this->ensureCheckoutEnabled();

        if ($redirect = $this->membersOnlyRedirect()) {
            return $redirect;
        }

        // Only offer payment methods the admin has enabled.
        $allowedMethods = array_values(array_filter([
            $this->onlineEnabled() ? Order::PAYMENT_ONLINE : null,
            $this->flag('payments.cod.enabled') ? Order::PAYMENT_COD : null,
        ]));

        $billingSame = $request->boolean('billing_same');

        $validated = $request->validate([
            'buyer_name' => ['required', 'string', 'max:150'],
            'buyer_email' => ['required', 'email', 'max:255'],
            'buyer_phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'ship_line1' => ['required', 'string', 'max:255'],
            'ship_line2' => ['nullable', 'string', 'max:255'],
            'ship_city' => ['required', 'string', 'max:100'],
            'ship_state' => ['required', 'string', 'max:64'],
            'ship_pincode' => ['required', 'regex:/^\d{6}$/'],
            'payment_method' => ['required', Rule::in($allowedMethods)],
            'billing_same' => ['nullable', 'boolean'],
            // Billing fields are required only when "same as shipping" is OFF.
            'bill_line1' => [Rule::requiredIf(! $billingSame), 'nullable', 'string', 'max:255'],
            'bill_line2' => ['nullable', 'string', 'max:255'],
            'bill_city' => [Rule::requiredIf(! $billingSame), 'nullable', 'string', 'max:100'],
            'bill_state' => [Rule::requiredIf(! $billingSame), 'nullable', 'string', 'max:64'],
            'bill_pincode' => [Rule::requiredIf(! $billingSame), 'nullable', 'regex:/^\d{6}$/'],
            'accept_terms' => ['required', 'accepted'],
        ]);

        $cart = $this->cartService->currentCart($request);
        $attr = $this->attribution->resolveForCheckout($request);

        $shipping = [
            'name' => $validated['buyer_name'],
            'phone' => '+91'.$validated['buyer_phone'],
            'line1' => $validated['ship_line1'],
            'line2' => $validated['ship_line2'] ?? null,
            'city' => $validated['ship_city'],
            'state' => $validated['ship_state'],
            'pincode' => $validated['ship_pincode'],
        ];

        $billing = $billingSame ? $shipping : [
            'name' => $validated['buyer_name'],
            'phone' => '+91'.$validated['buyer_phone'],
            'line1' => $validated['bill_line1'],
            'line2' => $validated['bill_line2'] ?? null,
            'city' => $validated['bill_city'],
            'state' => $validated['bill_state'],
            'pincode' => $validated['bill_pincode'],
        ];

        $order = $this->checkoutService->place(
            cart: $cart,
            buyer: [
                'name' => $validated['buyer_name'],
                'email' => $validated['buyer_email'],
                'phone' => '+91'.$validated['buyer_phone'],
                'marketing_opt_in' => (bool) $request->boolean('marketing_opt_in'),
            ],
            shipping: $shipping,
            billing: $billing,
            attributedDistributorId: $attr['distributor_id'],
            attributionSource: $attr['source'],
            paymentMethod: $validated['payment_method'],
        );

        // ONLINE: capture immediately via the gateway (Phase 2 stub auto-captures
        // → order paid). COD: leave the order PLACED (unpaid) — payment is
        // collected later and recorded by the admin "mark COD paid" action.
        if ($order->payment_method === Order::PAYMENT_ONLINE) {
            $intent = $this->gateway->createIntent($order, 'order:'.$order->id);
            $this->gateway->capture($intent);
        }

        // Generate the GST invoice for the order.
        $order->load('items');
        $this->invoiceGenerator->generate($order);

        return redirect()->route('shop.confirmation', $order->order_no);
    }

    public function confirmation(string $orderNo): View
    {
        $order = Order::with(['items.variant.product', 'customer'])
            ->where('order_no', $orderNo)->first();

        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return view('shop.confirmation', ['order' => $order]);
    }

    private function ensureCheckoutEnabled(): void
    {
        $enabled = DB::table('settings')->where('key', 'commerce.checkout.enabled')->value('value');
        if ($enabled !== 'true') {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Members-only buying: when guest checkout is disabled and the visitor is
     * not authenticated, send them to login first (preserving the intended
     * URL). Browsing the storefront stays open — only checkout is gated.
     */
    private function membersOnlyRedirect(): ?RedirectResponse
    {
        if ($this->flag('commerce.guest_checkout.enabled') || Auth::check()) {
            return null;
        }

        return redirect()->guest(route('login'))
            ->with('status', 'Please sign in to complete your purchase.');
    }

    private function flag(string $key): bool
    {
        return DB::table('settings')->where('key', $key)->value('value') === 'true';
    }

    /** Online payment is available if any online gateway is enabled. */
    private function onlineEnabled(): bool
    {
        return $this->flag('payments.gateway.stub.enabled') || $this->flag('payments.gateway.razorpay.enabled');
    }
}
