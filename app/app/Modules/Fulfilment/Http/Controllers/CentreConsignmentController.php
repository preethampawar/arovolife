<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Http\Controllers;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Fulfilment\Services\CollectionHandoverService;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * The centre owner's own view of the parcels sent to their centre (R-47): they
 * confirm a parcel has arrived, which emails the buyer a collection code, and
 * hand it over against the code the buyer presents.
 *
 * Shows only what the handover needs — order number, the buyer's first name,
 * how many items, and the dates. No phone, no address, no amounts: the
 * declaration's buyer-data duty, and minimisation.
 *
 * Does not exist while collection at a centre is switched off (R-97), and does
 * not exist for someone who runs no centre.
 */
final class CentreConsignmentController extends Controller
{
    public function __construct(private readonly CollectionHandoverService $handover) {}

    public function index(Request $request, FulfilmentSettings $fulfilment): View
    {
        $centreIds = $this->ownedCentreIds($request);

        $orders = Order::query()
            ->where('delivery_type', Order::DELIVERY_COLLECT)
            ->whereIn('arete_center_id', $centreIds)
            // Only parcels the centre is expecting or holding: once handed
            // over, the order is no longer the centre's to see (privacy 4a).
            ->whereIn('status', [Order::STATUS_SHIPPED, Order::STATUS_AWAITING_COLLECTION])
            ->with(['customer:id,display_name', 'areteCenter:id,name', 'shipment'])
            ->withSum('items as item_count', 'qty')
            ->orderBy('shipped_at')
            ->get();

        return view('my.arete-centre.consignments', [
            'groups' => $this->groups($orders),
            'maxDwellDays' => $fulfilment->maxDwellDays(),
            'showCentre' => $centreIds->count() > 1,
        ]);
    }

    public function received(Request $request, Order $order): RedirectResponse
    {
        $this->authorised($request, $order);

        try {
            $this->handover->acknowledgeArrival($order, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['consignment' => $e->getMessage()]);
        }

        return redirect()->route('my.adc.consignments')
            ->with('success', "Order {$order->order_no} is recorded as arrived. The buyer has been sent their collection code.");
    }

    public function handover(Request $request, Order $order): RedirectResponse
    {
        $this->authorised($request, $order);

        $validator = validator($request->all(), ['code' => ['required', 'digits:6']]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->with('handover_order', $order->order_no);
        }
        /** @var array{code: string} $validated */
        $validated = $validator->validated();

        try {
            $this->handover->recordCollection($order, $validated['code'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()])->with('handover_order', $order->order_no);
        }

        return redirect()->route('my.adc.consignments')
            ->with('success', "Order {$order->order_no} is handed over. Thank you.");
    }

    /** @return Collection<int, int> */
    private function ownedCentreIds(Request $request): Collection
    {
        abort_unless(AreteCenter::collectionEnabled(), 404);

        $distributorId = $request->user()?->distributor?->id;
        abort_if($distributorId === null, 404);

        $ids = AreteCenter::where('assigned_distributor_id', $distributorId)->pluck('id');
        abort_if($ids->isEmpty(), 404);

        return $ids->map(fn ($id): int => (int) $id);
    }

    private function authorised(Request $request, Order $order): void
    {
        $this->ownedCentreIds($request);

        abort_unless(Gate::forUser($request->user())->allows('actAtCentre', $order), 403);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array{on_the_way: Collection<int, Order>, waiting: Collection<int, Order>}
     */
    private function groups(Collection $orders): array
    {
        return [
            'on_the_way' => $orders->where('status', Order::STATUS_SHIPPED)->values(),
            'waiting' => $orders->where('status', Order::STATUS_AWAITING_COLLECTION)->values(),
        ];
    }
}
