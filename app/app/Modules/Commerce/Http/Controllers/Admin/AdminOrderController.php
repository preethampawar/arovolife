<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdminOrderController extends Controller
{
    public function __construct(private readonly OrderStateMachine $stateMachine) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');

        $orders = Order::with(['customer'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('placed_at')
            ->paginate(25);

        $statusCounts = Order::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return view('admin.commerce.orders-index', [
            'orders' => $orders,
            'statusCounts' => $statusCounts,
        ]);
    }

    public function show(Order $order): View
    {
        $order->load(['customer', 'items.variant', 'coolingOff', 'distributor']);

        return view('admin.commerce.orders-show', ['order' => $order]);
    }

    public function markCodPaid(Order $order): RedirectResponse
    {
        // markPaid guards status === placed and posts the COD cash-in ledger
        // entry for cod orders (no-op ledger for online).
        $this->stateMachine->markPaid($order, auth()->id());

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', "Order {$order->order_no} marked paid (COD collected).");
    }

    public function markShipped(Order $order): RedirectResponse
    {
        $this->stateMachine->markShipped($order, auth()->id());

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', "Order {$order->order_no} marked shipped.");
    }

    public function markDelivered(Order $order): RedirectResponse
    {
        $this->stateMachine->markDelivered($order, auth()->id());

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', 'Delivery recorded. 30-day cooling-off clock opened.');
    }
}
