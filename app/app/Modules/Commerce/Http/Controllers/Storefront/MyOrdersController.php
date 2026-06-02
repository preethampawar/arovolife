<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Commerce\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The authenticated distributor's own order history. Every order shown is
 * scoped to a customer record claimed by the logged-in user (set at checkout
 * by CheckoutService), so a distributor can only ever see their own orders.
 */
final class MyOrdersController extends Controller
{
    public function index(Request $request): View
    {
        $orders = Order::query()
            ->whereHas('customer', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['items', 'coolingOff', 'bvLedgerEntries'])
            ->latest('placed_at')
            ->paginate(15);

        return view('shop.orders.index', [
            'orders' => $orders,
            // BV is a distributor-only figure (hard rule #3) — a non-distributor
            // customer never sees it, consistent with cart/checkout/confirmation.
            'showBv' => $request->user()?->distributor !== null,
        ]);
    }

    public function show(Request $request, string $orderNo): View
    {
        $order = Order::query()
            ->where('order_no', $orderNo)
            ->whereHas('customer', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['items.variant.product', 'coolingOff', 'bvLedgerEntries'])
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return view('shop.orders.show', [
            'order' => $order,
            'showBv' => $request->user()?->distributor !== null,
        ]);
    }
}
