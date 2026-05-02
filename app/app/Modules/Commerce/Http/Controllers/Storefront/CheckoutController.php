<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Payments\Services\StubGateway;
use App\Modules\Tax\Services\InvoiceGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly AttributionService $attribution,
        private readonly CheckoutService $checkoutService,
        private readonly StubGateway $gateway,
        private readonly InvoiceGenerator $invoiceGenerator,
    ) {}

    public function show(Request $request): View
    {
        $this->ensureCheckoutEnabled();

        $cart = $this->cartService->currentCart($request);
        $cart->load('items.variant.product');

        if ($cart->items->isEmpty()) {
            abort(302, 'Cart empty', ['Location' => route('shop.index')]);
        }

        $guestAllowed = DB::table('settings')
            ->where('key', 'commerce.guest_checkout.enabled')->value('value') === 'true';

        return view('shop.checkout', [
            'cart' => $cart,
            'guestAllowed' => $guestAllowed,
            'refAdn' => $request->cookie(AttributionService::COOKIE_NAME),
        ]);
    }

    public function place(Request $request): RedirectResponse
    {
        $this->ensureCheckoutEnabled();

        $validated = $request->validate([
            'buyer_name' => ['required', 'string', 'max:150'],
            'buyer_email' => ['required', 'email', 'max:255'],
            'buyer_phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'ship_line1' => ['required', 'string', 'max:255'],
            'ship_line2' => ['nullable', 'string', 'max:255'],
            'ship_city' => ['required', 'string', 'max:100'],
            'ship_state' => ['required', 'string', 'max:64'],
            'ship_pincode' => ['required', 'regex:/^\d{6}$/'],
            'accept_terms' => ['required', 'accepted'],
        ]);

        $cart = $this->cartService->currentCart($request);

        // Determine attribution
        $attr = $this->attribution->resolveForCheckout($request);

        $order = $this->checkoutService->place(
            cart: $cart,
            buyer: [
                'name' => $validated['buyer_name'],
                'email' => $validated['buyer_email'],
                'phone' => '+91'.$validated['buyer_phone'],
                'marketing_opt_in' => (bool) $request->boolean('marketing_opt_in'),
            ],
            shipping: [
                'name' => $validated['buyer_name'],
                'phone' => '+91'.$validated['buyer_phone'],
                'line1' => $validated['ship_line1'],
                'line2' => $validated['ship_line2'] ?? null,
                'city' => $validated['ship_city'],
                'state' => $validated['ship_state'],
                'pincode' => $validated['ship_pincode'],
            ],
            attributedDistributorId: $attr['distributor_id'],
            attributionSource: $attr['source'],
        );

        // Stub gateway: create + capture in one go
        $intent = $this->gateway->createIntent($order, 'order:'.$order->id);
        $this->gateway->capture($intent);

        // Generate invoice
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
}
