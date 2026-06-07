<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\CouponService;
use App\Modules\Commerce\Services\CustomerAddressService;
use App\Modules\Commerce\Services\ShippingService;
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
        private readonly ShippingService $shipping,
        private readonly CustomerAddressService $addressBook,
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

        // The logged-in buyer's saved shipping addresses, for the checkout
        // picker (default first). Guests / not-yet-customers see none.
        $savedAddresses = collect();
        $userId = $request->user()?->id;
        if ($userId !== null) {
            $customer = Customer::where('user_id', $userId)->first();
            if ($customer !== null) {
                $savedAddresses = $this->addressBook->forCustomer($customer->id);
            }
        }

        // The logged-in distributor's own identity, shown as a read-only block
        // and offered as a "same as distributor" shortcut for the customer
        // fields (self-consumption is the common case). Null for guests /
        // plain customers, who only see the editable customer block.
        $buyerDistributor = null;
        $authUser = $request->user();
        if ($authUser?->distributor !== null) {
            $buyerDistributor = [
                'adn' => $authUser->distributor->adn,
                'name' => $authUser->full_name,
                'email' => $authUser->email,
                'phone_e164' => $authUser->phone_e164,
                // 10-digit local form for the buyer_phone field (drops +91).
                'phone_local' => preg_replace('/^\+91/', '', (string) $authUser->phone_e164),
            ];
        }

        return view('shop.checkout', [
            'cart' => $cart,
            'couponDiscount' => $couponDiscount,
            'shippingPaise' => $this->shipping->feePaise($cart->subtotalPaise()),
            'guestAllowed' => $guestAllowed,
            'onlineEnabled' => $this->onlineEnabled(),
            'codEnabled' => $this->flag('payments.cod.enabled'),
            'refAdn' => $request->cookie(AttributionService::COOKIE_NAME),
            'savedAddresses' => $savedAddresses,
            'presetLabels' => CustomerAddressService::PRESET_LABELS,
            'buyerDistributor' => $buyerDistributor,
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
            'save_address' => ['nullable', 'boolean'],
            'address_label' => ['nullable', 'string', 'max:40'],
            'accept_terms' => ['required', 'accepted'],
        ]);

        $cart = $this->cartService->currentCart($request);

        // A logged-in distributor buying their own products is attributed to
        // themselves when the admin setting allows (default on) — this is what
        // makes the order self-consumption, so its BV accrues to their personal
        // ledger after cooling-off (ADR-0006). Otherwise fall back to the
        // referral cookie / house attribution.
        $loggedInDistributorId = Auth::user()?->distributor?->id;
        $attr = $this->attribution->resolveForCheckout(
            $request,
            ($loggedInDistributorId !== null && $this->flag('commerce.attribution.logged_in_overrides_ref'))
                ? $loggedInDistributorId
                : null,
        );

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
            authUserId: Auth::id(),
            buyerDistributorId: Auth::user()?->distributor?->id,
            // Save the shipping address to the book only for a logged-in buyer
            // who opted in (the checkbox defaults to on). Guests have no account
            // to save against.
            saveShippingAddress: Auth::check() && $request->boolean('save_address'),
            shippingLabel: $validated['address_label'] ?? null,
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

        // Bind this order to the buyer's session so the confirmation page is
        // viewable by the person who just placed it (incl. guests) without
        // exposing it to anyone who can guess the order number (IDOR).
        $request->session()->push('recent_order_nos', $order->order_no);

        return redirect()->route('shop.confirmation', $order->order_no);
    }

    public function confirmation(Request $request, string $orderNo): View
    {
        $order = Order::with(['items.variant.product', 'customer'])
            ->where('order_no', $orderNo)->first();

        // Only the buyer may view their confirmation: either they just placed
        // it (session-bound) or they are the authenticated owner. Anyone else
        // (e.g. a distributor enumerating order numbers) gets a 404 — this
        // protects both the order PII and the BV figure (hard rule #3).
        if ($order === null || ! $this->canViewConfirmation($request, $order)) {
            throw new NotFoundHttpException;
        }

        return view('shop.confirmation', [
            'order' => $order,
            // BV is shown only to the authenticated owner who is a distributor.
            'showBv' => $this->ownsOrder($request, $order) && $request->user()?->distributor !== null,
        ]);
    }

    private function canViewConfirmation(Request $request, Order $order): bool
    {
        $recent = (array) $request->session()->get('recent_order_nos', []);

        return in_array($order->order_no, $recent, true) || $this->ownsOrder($request, $order);
    }

    private function ownsOrder(Request $request, Order $order): bool
    {
        $userId = $request->user()?->id;

        return $userId !== null && $order->customer !== null && $order->customer->user_id === $userId;
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
