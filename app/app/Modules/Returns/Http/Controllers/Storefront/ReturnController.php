<?php

declare(strict_types=1);

namespace App\Modules\Returns\Http\Controllers\Storefront;

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Returns\Services\BuybackMatrix;
use App\Modules\Returns\Services\OpenReturn;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Customer-initiated return requests — storefront.
 *
 * create() — show the return form (within the applicable window).
 * store()  — open a return request; for cooling_off, executes the refund immediately.
 * show()   — return request status page.
 */
final class ReturnController extends Controller
{
    public function __construct(
        private readonly OpenReturn $openReturn,
    ) {}

    /** Return request form — shown only when the order is in a returnable state. */
    public function create(Request $request, string $orderNo): View
    {
        $order = $this->resolveOrder($request, $orderNo);
        $order->loadMissing('coolingOff');

        return view('shop.returns.create', [
            'order' => $order,
            'reasons' => BuybackMatrix::REASONS,
            'coolingOff' => $order->coolingOff,
        ]);
    }

    /** Open the return request. */
    public function store(Request $request, string $orderNo): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'in:'.implode(',', BuybackMatrix::REASONS)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $this->resolveOrder($request, $orderNo);
        $order->loadMissing(['coolingOff', 'items.variant.inventory']);

        $customer = Customer::where('user_id', $request->user()->id)->firstOrFail();

        try {
            $returnRequest = $this->openReturn->execute(
                order: $order,
                customer: $customer,
                reason: $validated['reason'],
                notes: $validated['notes'] ?? null,
                actorUserId: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return redirect()->route('orders.show', $order->order_no)
                ->withErrors(['return' => $e->getMessage()]);
        }

        $order->refresh();
        if ($order->status === Order::STATUS_REFUND_APPROVED) {
            return redirect()->route('orders.show', $order->order_no)
                ->with('status', 'Your return has been accepted and your refund is being processed. You will receive the amount within 7 working days.');
        }

        return redirect()->route('orders.show', $order->order_no)
            ->with('status', "Return request {$returnRequest->rma_no} submitted. Our team will review it shortly.");
    }

    /** Resolve an order belonging to the authenticated customer. */
    private function resolveOrder(Request $request, string $orderNo): Order
    {
        $order = Order::query()
            ->where('order_no', $orderNo)
            ->whereHas('customer', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['items', 'coolingOff'])
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return $order;
    }
}
