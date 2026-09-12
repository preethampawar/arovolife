<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventorySettings;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Tax\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

final class AdminOrderController extends Controller
{
    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly OrderFulfilmentService $fulfilment,
        private readonly InventorySettings $inventorySettings,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');

        // `items` is eager-loaded so the BV column can call Order::bvTotalPaise()
        // (sum of line BV) without an N+1 across the page of orders.
        $orders = Order::with(['customer', 'items'])
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

        // The tax invoice and the gateway intent are owned by other modules but
        // belong on this page: support could otherwise neither see a buyer's
        // invoice nor reach the payment behind the order (QA F101).
        return view('admin.commerce.orders-show', [
            'order' => $order,
            'invoice' => Invoice::where('order_id', $order->id)->latest('id')->first(),
            'paymentIntent' => PaymentIntent::where('order_id', $order->id)->latest('id')->first(),
            // Inventory plan H7: pick list, shipment and pack warehouses.
            'pickList' => $this->fulfilment->pickList($order),
            'shipment' => Shipment::where('order_id', $order->id)->latest('id')->first(),
            'packWarehouses' => Warehouse::query()->fulfilling()->orderBy('name')->get(),
            'defaultWarehouseCode' => $this->inventorySettings->defaultWarehouseCode(),
        ]);
    }

    /** Inventory plan H7: pick FEFO batches for a paid order and mark it packed. */
    public function pack(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'warehouse_code' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $this->fulfilment->pack($order, $validated['warehouse_code'] ?? null, (int) auth()->id());
        } catch (\RuntimeException $e) {
            Log::warning('Order pack refused', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['pack' => $e->getMessage()]);
        }

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', "Order {$order->order_no} packed.");
    }

    public function markShipped(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'ship_carrier' => ['nullable', 'string', 'max:120'],
            'ship_tracking_no' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $this->stateMachine->markShipped(
                $order,
                auth()->id(),
                $validated['ship_carrier'] ?? null,
                $validated['ship_tracking_no'] ?? null,
            );
        } catch (\RuntimeException $e) {
            Log::warning('Order ship transition refused', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['ship' => $e->getMessage()]);
        }

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', "Order {$order->order_no} marked shipped.");
    }

    public function markDelivered(Order $order): RedirectResponse
    {
        try {
            $this->stateMachine->markDelivered($order, auth()->id());
        } catch (\RuntimeException $e) {
            Log::warning('Order deliver transition refused', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['deliver' => $e->getMessage()]);
        }

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', 'Delivery recorded. 30-day cooling-off clock opened.');
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->stateMachine->cancel($order, $validated['reason'] ?? 'Cancelled by admin', (int) auth()->id());
        } catch (\RuntimeException $e) {
            return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', "Order {$order->order_no} cancelled. Reserved stock released.");
    }
}
