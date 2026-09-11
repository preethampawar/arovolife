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
        private readonly BuybackMatrix $matrix,
    ) {}

    /** Return request form — shown only when the order is in a returnable state. */
    public function create(Request $request, string $orderNo): View
    {
        $order = $this->resolveOrder($request, $orderNo);
        $order->loadMissing('coolingOff');

        return view('shop.returns.create', [
            'order' => $order,
            'reasons' => $this->reasonDisclosures($order),
            'coolingOff' => $order->coolingOff,
        ]);
    }

    /**
     * What each return reason is worth on THIS order, straight from the T&C §8
     * buy-back matrix the refund engine uses.
     *
     * The buyer used to see only the window per reason and picked blind: the
     * damage-non-saleable, general-buyback and termination-buyback rows refund
     * the Direct Seller Price *less GST* as a voucher, and shipping comes back
     * only on a cooling-off cancellation. DSR 2021 requires the buy-back terms
     * to be disclosed before the buyer commits, so the form now states the
     * deduction for every reason (QA F100).
     *
     * `refund_paise` mirrors the RefundOrder service: taxable + GST (when the policy
     * refunds it) + shipping (cooling-off only) − the coupon discount, which is
     * never refunded as cash. Redeemed points and repurchase-wallet credit come
     * back in their own form and are called out in the view instead.
     *
     * @return list<array{reason: string, label: string, window_days: int|null, saleable_only: bool, refunds_gst: bool, refunds_shipping: bool, refund_paise: int, non_saleable_refund_paise: int|null}>
     */
    private function reasonDisclosures(Order $order): array
    {
        $labels = [
            'cooling_off' => 'Cooling-off cancellation',
            'damage' => 'Damaged on arrival',
            'dissatisfaction' => 'Dissatisfied with the product',
            'general_buyback' => 'General buyback',
            'termination_buyback' => 'Termination / account closure buyback',
        ];

        $taxable = $order->subtotal_paise - $order->gst_paise;
        $disclosures = [];

        foreach (BuybackMatrix::REASONS as $reason) {
            $saleable = $this->matrix->policy($reason, true);
            $nonSaleable = $this->matrix->policy($reason, false);
            $shipping = $reason === 'cooling_off' ? $order->shipping_paise : 0;

            $disclosures[] = [
                'reason' => $reason,
                'label' => $labels[$reason],
                'window_days' => $saleable['window_days'],
                'saleable_only' => ! $nonSaleable['eligible'],
                'refunds_gst' => $saleable['refund_gst'],
                'refunds_shipping' => $shipping > 0,
                'refund_paise' => max(0, $this->matrix->refundPaise($reason, true, $taxable, $order->gst_paise)
                    + $shipping - $order->discount_paise),
                'non_saleable_refund_paise' => $nonSaleable['eligible']
                    ? max(0, $this->matrix->refundPaise($reason, false, $taxable, $order->gst_paise) - $order->discount_paise)
                    : null,
            ];
        }

        return $disclosures;
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
