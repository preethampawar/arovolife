<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Http\Controllers\Admin;

use App\Modules\Commerce\Models\Order;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Services\CourierGatewayResolver;
use App\Modules\Fulfilment\Services\ShiprocketGateway;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * Orders waiting to leave the warehouse, oldest first (plan AD-8).
 *
 * A worklist, not a place to act: dispatch happens on the order page, where
 * the route picker, the parcel-detail alert, any half-finished courier booking
 * and the confirmation all sit beside the order itself. This page only says
 * which orders are waiting and whether each could go through Shiprocket.
 */
final class AdminDispatchController extends Controller
{
    public function __construct(private readonly CourierGatewayResolver $couriers) {}

    public function index(): View
    {
        $orders = Order::query()
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_READY_TO_SHIP])
            ->with(['items.variant', 'areteCenter'])
            ->withCount('items')
            ->oldest('placed_at')
            ->oldest('id')
            ->paginate(50);

        $shiprocket = $this->couriers->available()[Shipment::GATEWAY_SHIPROCKET] ?? null;

        // Only computed when Shiprocket is actually offered: with it off, the
        // column would describe a choice nobody has (zero-trace gating).
        $gapCounts = [];
        if ($shiprocket instanceof ShiprocketGateway) {
            foreach ($orders as $order) {
                $gapCounts[$order->id] = count($shiprocket->parcelGaps($order));
            }
        }

        $pendingBookings = Shipment::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('gateway', '!=', Shipment::GATEWAY_MANUAL)
            ->pluck('gateway', 'order_id')
            ->all();

        return view('admin.fulfilment.dispatch-queue', [
            'orders' => $orders,
            'shiprocketOffered' => $shiprocket !== null,
            'gapCounts' => $gapCounts,
            'pendingBookings' => $pendingBookings,
        ]);
    }
}
