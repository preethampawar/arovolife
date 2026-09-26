<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Fulfilment\Data\CourierQuote;
use App\Modules\Fulfilment\Exceptions\ShiprocketApiException;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Services\CollectionHandoverService;
use App\Modules\Fulfilment\Services\CourierGatewayResolver;
use App\Modules\Fulfilment\Services\CourierTrackingSync;
use App\Modules\Fulfilment\Services\DispatchService;
use App\Modules\Fulfilment\Services\ManualCourier;
use App\Modules\Fulfilment\Services\ShiprocketGateway;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventorySettings;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Shared\Features\OfflineOrdersFeature;
use App\Modules\Shared\Features\ShiprocketFulfilmentFeature;
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\IndianNumber;
use App\Modules\Shared\Support\ListFilters;
use App\Modules\Tax\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

final class AdminOrderController extends Controller
{
    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly OrderFulfilmentService $fulfilment,
        private readonly InventorySettings $inventorySettings,
        private readonly CourierGatewayResolver $couriers,
        private readonly DispatchService $dispatch,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');

        // The page opens on today's order book, not on every order ever
        // placed. The default is injected into the query bag rather than
        // applied behind the toolbar's back, so the date inputs, the chips,
        // the status chip links and the paginator all carry the window that is
        // actually in force. `has()` and not `query()` decides: pressing
        // Filter with both dates emptied submits them as empty keys, and that
        // — an explicitly cleared range — is how a viewer asks for all time.
        //
        // A request that already asks for something specific is left alone: a
        // dashboard tile linking here with `?status=paid` counts every paid
        // order, and must land on the list it counted rather than on today's
        // slice of it. Chip links from this page carry the dates, so choosing
        // a status here keeps the window.
        $defaultedToToday = ! $request->hasAny(['placed_from', 'placed_to', 'status', 'q', 'payment_method']);

        if ($defaultedToToday) {
            $today = Carbon::today()->toDateString();
            $request->query->set('placed_from', $today);
            $request->query->set('placed_to', $today);
        }

        // `status` stays a chip, not a ListFilters field (A6): the existing
        // OrderStatusBadge::FILTERABLE chip row is kept and rebuilt to
        // preserve the toolbar's own filters.
        $offlineOrdersOn = Feature::for(null)->active(OfflineOrdersFeature::class);

        $filters = ListFilters::make($request, [
            FilterField::text('q', 'Search', 'Order #', columns: ['orders.order_no']),
            FilterField::dateRange('placed', 'Placed', dateColumn: 'orders.placed_at'),
            // Offline orders only exist while the feature is on; the filter
            // follows the flag so an OFF platform shows no trace of it.
            ...($offlineOrdersOn ? [FilterField::select('payment_method', 'Payment', [
                Order::PAYMENT_ONLINE => 'Online',
                Order::PAYMENT_OFFLINE => 'Offline',
            ], column: 'orders.payment_method', placeholder: 'Any payment')] : []),
        ]);

        // One definition of "the rows this page is showing", re-derived per
        // query: ListFilters applies its clauses to the builder it is handed,
        // so the page of rows and each summary figure need their own.
        $scoped = function () use ($filters, $status): EloquentBuilder {
            /** @var EloquentBuilder<Order> $query */
            $query = $filters->apply(
                Order::query()->when($status, fn ($q) => $q->where('status', $status))
            );

            return $query;
        };

        // `items` is eager-loaded so the BV column can call Order::bvTotalPaise()
        // (sum of line BV) without an N+1 across the page of orders.
        $orders = $scoped()
            ->with(['customer.distributor', 'distributor', 'items'])
            ->orderByDesc('placed_at')
            ->paginate(25)
            ->withQueryString();

        // Chip counts drop the status clause and keep every other filter: the
        // chips are how you switch status, so each must count what it would
        // show, inside the window the viewer is already in.
        $statusCounts = $filters->apply(Order::query())
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        // Repurchase wallet applied at checkout (Compensation module) keyed by
        // order id, so the list can show it without an N+1 per row.
        $repurchaseWalletByOrder = WalletLedgerEntry::query()
            ->where('reference_type', 'order')
            ->where('type', 'repurchase_wallet_used')
            ->whereIn('reference_id', $orders->pluck('id'))
            ->pluck('amount_paise', 'reference_id')
            ->map(fn (int $amountPaise): int => abs($amountPaise));

        return view('admin.commerce.orders-index', [
            'orders' => $orders,
            'filters' => $filters,
            'statusCounts' => $statusCounts,
            'repurchaseWalletByOrder' => $repurchaseWalletByOrder,
            'summary' => $this->summary($scoped),
            'defaultedToToday' => $defaultedToToday,
            'offlineOrdersOn' => $offlineOrdersOn,
        ]);
    }

    /**
     * The figures over the whole filtered set — not over the page of 25, which
     * is what a footer row would give. Three aggregate queries against the
     * same clauses the list is built from, so narrowing the filters (or the
     * status chip) moves the tiles with the rows.
     *
     * `total_paise` is the money still payable after repurchase-wallet credit,
     * which is why the wallet figure is its own tile rather than folded in:
     * the two settle the same sale and only one of them is cash.
     *
     * @param  callable(): EloquentBuilder<Order>  $scoped
     * @return array{orders: int, total_paise: int, gst_paise: int, repurchase_wallet_paise: int, bv_paise: int}
     */
    private function summary(callable $scoped): array
    {
        /** @var object{order_count: int|string, total_paise: int|string|null, gst_paise: int|string|null}|null $totals */
        $totals = $scoped()
            ->toBase()
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_paise), 0) as total_paise, COALESCE(SUM(gst_paise), 0) as gst_paise')
            ->first();

        return [
            'orders' => (int) ($totals->order_count ?? 0),
            'total_paise' => (int) ($totals->total_paise ?? 0),
            'gst_paise' => (int) ($totals->gst_paise ?? 0),
            // Negative in the ledger (it is a debit); shown as the amount settled.
            'repurchase_wallet_paise' => abs((int) WalletLedgerEntry::query()
                ->where('reference_type', 'order')
                ->where('type', 'repurchase_wallet_used')
                ->whereIn('reference_id', $scoped()->select('orders.id'))
                ->sum('amount_paise')),
            // Line BV is qty × the snapshot BV, the same arithmetic as
            // OrderItem::lineBvPaise(); summed in SQL because the filtered set
            // is not the page and must not be hydrated to be added up.
            'bv_paise' => (int) DB::table('order_items')
                ->whereIn('order_id', $scoped()->select('orders.id'))
                ->sum(DB::raw('qty * bv_paise')),
        ];
    }

    public function show(Order $order): View
    {
        $order->load(['customer', 'items.variant', 'coolingOff', 'distributor', 'areteCenter', 'offlinePayment.recordedBy', 'offlinePayment.confirmedBy', 'offlinePayment.rejectedBy']);

        $shipment = Shipment::where('order_id', $order->id)->latest('id')->first();

        // The dispatch form's choices. Only while the order can still be
        // dispatched: a shipped order has nothing to choose.
        $dispatchable = in_array($order->status, [Order::STATUS_PAID, Order::STATUS_READY_TO_SHIP], true);
        $routes = $dispatchable ? $this->couriers->available() : [];
        $shiprocket = $routes[Shipment::GATEWAY_SHIPROCKET] ?? null;

        // The tax invoice and the gateway intent are owned by other modules but
        // belong on this page: support could otherwise neither see a buyer's
        // invoice nor reach the payment behind the order (QA F101).
        return view('admin.commerce.orders-show', [
            'order' => $order,
            'invoice' => Invoice::where('order_id', $order->id)->latest('id')->first(),
            'paymentIntent' => PaymentIntent::where('order_id', $order->id)->latest('id')->first(),
            'repurchaseWalletDebit' => WalletLedgerEntry::query()
                ->where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->where('type', 'repurchase_wallet_used')
                ->latest('id')
                ->first(),
            // Inventory plan H7: pick list, shipment and pack warehouses.
            'pickList' => $this->fulfilment->pickList($order),
            'shipment' => $shipment,
            'dispatchRoutes' => array_keys($routes),
            'preferredRoute' => $dispatchable ? $this->couriers->preferred()->name() : Shipment::GATEWAY_MANUAL,
            'parcelGaps' => $shiprocket instanceof ShiprocketGateway ? $shiprocket->parcelGaps($order) : [],
            // A courier booking made (or requested with no reply) for a parcel
            // that has not shipped: the form must make the operator deal with it.
            'pendingBooking' => $dispatchable && $shipment !== null && $shipment->gateway !== Shipment::GATEWAY_MANUAL ? $shipment : null,
            'packWarehouses' => Warehouse::query()->fulfilling()->orderBy('name')->get(),
            'defaultWarehouseCode' => $this->inventorySettings->defaultWarehouseCode(),
            'offlineOrdersOn' => Feature::for(null)->active(OfflineOrdersFeature::class),
            'canCheckTracking' => $this->trackable($order, $shipment),
        ]);
    }

    /** A Shiprocket-booked parcel the courier still has, while the gateway flag is on. */
    private function trackable(Order $order, ?Shipment $shipment): bool
    {
        return $shipment !== null
            && $shipment->gateway === Shipment::GATEWAY_SHIPROCKET
            && $shipment->gateway_shipment_id !== null
            && in_array($order->status, [Order::STATUS_SHIPPED, Order::STATUS_AWAITING_COLLECTION], true)
            && Feature::for(null)->active(ShiprocketFulfilmentFeature::class);
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

    /**
     * Dispatch through the chosen courier route. Everything goes through
     * `DispatchService`, so the courier leg, the consignee, the shipment's
     * gateway columns and the declaration gate apply to every ship.
     */
    public function markShipped(Request $request, Order $order): RedirectResponse
    {
        $request->mergeIfMissing(['route' => Shipment::GATEWAY_MANUAL]);

        $validated = $request->validate([
            'route' => ['required', 'string', Rule::in([Shipment::GATEWAY_MANUAL, Shipment::GATEWAY_SHIPROCKET])],
            // Limits match what the shipment row stores, so nothing is cut short silently.
            'ship_carrier' => ['required_if:route,manual', 'nullable', 'string', 'max:'.ManualCourier::CARRIER_MAX],
            'ship_tracking_no' => ['nullable', 'string', 'max:'.ManualCourier::AWB_MAX],
            'confirm_remote_cancelled' => ['sometimes', 'boolean'],
            // Only the courier's id: its rate is re-read from Shiprocket, never taken from the form.
            'courier_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'ship_carrier.required_if' => 'Enter the courier carrying this parcel.',
        ]);

        $manual = $validated['route'] === Shipment::GATEWAY_MANUAL;

        try {
            $this->dispatch->dispatch(
                $order,
                $validated['route'],
                $manual ? ($validated['ship_carrier'] ?? null) : null,
                $manual ? ($validated['ship_tracking_no'] ?? null) : null,
                is_numeric(auth()->id()) ? (int) auth()->id() : null,
                confirmedRemoteCancelled: $request->boolean('confirm_remote_cancelled'),
                courierId: $manual || ! isset($validated['courier_id']) ? null : (int) $validated['courier_id'],
            );
        } catch (\RuntimeException $e) {
            Log::warning('Order ship transition refused', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'route' => $validated['route'],
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['ship' => $e->getMessage()]);
        }

        $order->refresh();
        $how = $order->ship_carrier !== null && $order->ship_carrier !== '' ? " via {$order->ship_carrier}" : '';
        $awb = $order->ship_tracking_no !== null && $order->ship_tracking_no !== '' ? ", AWB {$order->ship_tracking_no}" : '';
        $shipment = Shipment::where('order_id', $order->id)->first();
        $quoted = '';
        if ($shipment?->quoted_rate_paise !== null) {
            $days = $shipment->quoted_etd_days;
            $quoted = ', quoted ₹'.IndianNumber::format($shipment->quoted_rate_paise / 100, 2)
                .($days !== null ? ", {$days} ".($days === 1 ? 'day' : 'days') : '');
        }

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', "Order {$order->order_no} shipped{$how}{$awb}{$quoted}.");
    }

    /**
     * The couriers Shiprocket offers for this parcel, for the dispatch form.
     * Staff-only figures: what the company would pay, not what the buyer paid.
     */
    public function courierQuotes(Order $order): JsonResponse
    {
        try {
            $quotes = $this->dispatch->courierQuotes($order, Shipment::GATEWAY_SHIPROCKET);
        } catch (ShiprocketApiException $e) {
            Log::warning('Courier quotes unavailable', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Shiprocket could not list couriers right now. You can still dispatch: Shiprocket will choose the courier.'], 422);
        } catch (\RuntimeException $e) {
            // A database fault is not a message for the page.
            if ($e instanceof \PDOException) {
                throw $e;
            }

            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['quotes' => array_map(fn (CourierQuote $quote): array => $quote->toArray(), $quotes)]);
    }

    /**
     * The parcel has reached the collection centre. Until R-47's centre-side
     * surface ships, an operator records this on the centre's word — which is
     * still a recorded acknowledgement, and strictly better than the buyer
     * being told nothing at all.
     */
    public function markAwaitingCollection(Order $order): RedirectResponse
    {
        $actorId = auth()->id();

        try {
            $code = app(CollectionHandoverService::class)->acknowledgeArrival($order, is_numeric($actorId) ? (int) $actorId : null);
        } catch (\RuntimeException $e) {
            Log::warning('Order awaiting-collection transition refused', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['collection' => $e->getMessage()]);
        }

        // Still shown to the operator once: email is not instant and a buyer
        // standing at the counter should not be turned away over it.
        return redirect()->route('admin.commerce.orders.show', $order)
            ->with('status', "Recorded as ready to collect. The buyer has been emailed collection code {$code} — it will not be shown again.");
    }

    /** A fresh collection code for a parcel waiting at a centre, shown once. */
    public function reissueCollectionCode(Order $order): RedirectResponse
    {
        try {
            $code = app(CollectionHandoverService::class)->reissueCode($order, is_numeric(auth()->id()) ? (int) auth()->id() : null);
        } catch (\RuntimeException $e) {
            return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['collection' => $e->getMessage()]);
        }

        return redirect()->route('admin.commerce.orders.show', $order)
            ->with('status', "New collection code {$code} issued and emailed to the buyer. The old code no longer works, and it will not be shown again.");
    }

    /**
     * Ask Shiprocket for the parcel's status now, for when a tracking webhook
     * never arrived. It goes through the same API-verified path as the webhook,
     * so a DELIVERED here opens cooling-off exactly as the webhook would.
     */
    public function checkTracking(Order $order): RedirectResponse
    {
        $shipment = Shipment::where('order_id', $order->id)->latest('id')->first();
        if ($shipment === null || ! $this->trackable($order, $shipment)) {
            return redirect()->route('admin.commerce.orders.show', $order)
                ->withErrors(['tracking' => 'This order has no Shiprocket parcel in transit to check.']);
        }

        $actorId = is_numeric(auth()->id()) ? (int) auth()->id() : null;

        // A check records what the courier said, not a change of its own (the
        // sync audits any status it applies), so there is no before-state.
        $audit = fn (string $outcome, ?string $courierSays): AuditLog => AuditLog::create([
            'actor_id' => $actorId,
            'action' => 'shipment.tracking_checked',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'before_hash' => null,
            'after_hash' => AuditDigests::of(['outcome' => $outcome, 'courier_status' => $courierSays]),
            'details' => ['order_no' => $order->order_no, 'outcome' => $outcome, 'courier_status' => $courierSays],
        ]);

        try {
            $outcome = app(CourierTrackingSync::class)->sync($shipment, $actorId, ['trigger' => 'staff_check']);
        } catch (ShiprocketApiException|ConnectionException $e) {
            Log::warning('shiprocket tracking check failed', ['order_id' => $order->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            $audit('error: shiprocket unreachable', null);

            return redirect()->route('admin.commerce.orders.show', $order)
                ->withErrors(['tracking' => 'Shiprocket could not be reached. Nothing was changed; try again in a few minutes.']);
        } catch (\Throwable $e) {
            Log::error('shiprocket tracking check could not be applied', ['order_id' => $order->id, 'exception' => $e::class, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            $audit('error: not applied', null);

            return redirect()->route('admin.commerce.orders.show', $order)
                ->withErrors(['tracking' => 'The check could not be applied. Nothing was changed; try again, or tell the developer if it keeps happening.']);
        }

        $courierSays = $shipment->courier_status;
        $audit($outcome, $courierSays);

        $message = match ($outcome) {
            CourierTrackingSync::OUTCOME_DELIVERED => 'Shiprocket confirms the parcel was delivered. The order is now delivered and the buyer\'s 30-day cooling-off has started.',
            CourierTrackingSync::OUTCOME_DELIVERED_NO_CHANGE => match (true) {
                $order->isCollection() && $order->fresh()?->status === Order::STATUS_AWAITING_COLLECTION => 'Shiprocket says the parcel reached the centre, and its arrival is already recorded. It is delivered when the buyer collects it with their code.',
                $order->isCollection() => 'Shiprocket says the parcel reached the centre. Record its arrival once the centre confirms it.',
                default => 'Shiprocket says delivered; the order was already past shipped, so nothing changed.',
            },
            CourierTrackingSync::OUTCOME_RETURNING => "The courier is returning this parcel ({$courierSays}). It is listed in the Action Center.",
            CourierTrackingSync::OUTCOME_IN_TRANSIT => "Still in transit. The courier says: {$courierSays}.",
            default => 'Shiprocket has no tracking status for this parcel yet. Try again later.',
        };

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', $message);
    }

    public function markDelivered(Request $request, Order $order): RedirectResponse
    {
        // A collection is released against the buyer's code, whoever records
        // it: the handover record (collected_at) is what the ADC bonus is paid
        // on, and a bare "delivered" would leave the centre unpaid and the
        // handover unauthenticated.
        if ($order->isCollection()) {
            if ($order->status !== Order::STATUS_AWAITING_COLLECTION) {
                return redirect()->route('admin.commerce.orders.show', $order)
                    ->withErrors(['deliver' => 'A collection order is delivered only when the buyer collects it at the centre. Record its arrival first.']);
            }

            $validated = $request->validate(['code' => ['required', 'digits:6']]);

            try {
                app(CollectionHandoverService::class)->recordCollection($order, $validated['code'], is_numeric(auth()->id()) ? (int) auth()->id() : null);
            } catch (\RuntimeException $e) {
                return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['deliver' => $e->getMessage()]);
            }

            return redirect()->route('admin.commerce.orders.show', $order)->with('status', 'Collection recorded. 30-day cooling-off clock opened.');
        }

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

        // A pending offline order may hold money that is not on the books yet;
        // only finance can decide it, from the payment card (confirm, then
        // cancel — or reject when nothing was received).
        if ($order->isAwaitingOfflineConfirmation()) {
            return redirect()->route('admin.commerce.orders.show', $order)
                ->withErrors(['cancel' => 'This offline order is awaiting payment confirmation. Finance confirms or rejects it from the payment card.']);
        }

        try {
            $this->stateMachine->cancel($order, $validated['reason'] ?? 'Cancelled by admin', (int) auth()->id());
        } catch (\RuntimeException $e) {
            return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', "Order {$order->order_no} cancelled. Reserved stock released.");
    }
}
