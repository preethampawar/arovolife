<?php

declare(strict_types=1);

namespace App\Modules\Returns\Http\Controllers\Admin;

use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\InspectReturn;
use App\Modules\Returns\Services\ReturnReceiptService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Admin: inspect and decide on return requests (ADR-0009 build step 4–5).
 *
 * All routes gated by `can:finance.record` (R-17) in web.php.
 *
 * index()   — list all return requests, newest first.
 * show()    — return request detail + inspection/decision form.
 * inspect() — record physical condition + auto-compute BuybackDecision.
 * approve() — approve the refund (calls InspectReturn::approve → RefundOrder).
 * reject()  — reject the return, revert order to delivered.
 */
final class AdminReturnController extends Controller
{
    public function __construct(
        private readonly InspectReturn $inspect,
        private readonly ReturnReceiptService $receipt,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');

        $returns = ReturnRequest::with(['order.customer', 'inspection', 'buybackDecision'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(25);

        $statusCounts = ReturnRequest::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return view('admin.returns.index', [
            'returns' => $returns,
            'statusCounts' => $statusCounts,
        ]);
    }

    public function show(ReturnRequest $return): View
    {
        $return->load(['order.items', 'order.coolingOff', 'inspection', 'buybackDecision', 'openedByCustomer']);

        return view('admin.returns.show', ['return' => $return]);
    }

    public function inspect(Request $request, ReturnRequest $return): RedirectResponse
    {
        $validated = $request->validate([
            'condition' => ['required', 'string', 'in:saleable,non_saleable,damaged'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->inspect->record(
                returnRequest: $return,
                condition: $validated['condition'],
                notes: $validated['notes'] ?? null,
                inspectorUserId: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return redirect()->route('admin.returns.show', $return)->withErrors(['inspect' => $e->getMessage()]);
        }

        return redirect()->route('admin.returns.show', $return)->with('status', 'Inspection recorded. Review the computed refund and approve or reject.');
    }

    public function approve(Request $request, ReturnRequest $return): RedirectResponse
    {
        try {
            $this->inspect->approve($return, $request->user()->id);
        } catch (\RuntimeException $e) {
            return redirect()->route('admin.returns.show', $return)->withErrors(['approve' => $e->getMessage()]);
        }

        $orderNo = $return->order->order_no;

        return redirect()->route('admin.returns.show', $return)
            ->with('status', "Refund approved for order {$orderNo}. Ledger reversed, BV reversed, and the refund sent to the gateway; the customer receives it within 7 working days once the gateway confirms.");
    }

    /**
     * The goods are back (or our courier lost them): restores the points and
     * repurchase credit and releases the held gateway refund together.
     * Gated by `can:returns.receive` — deliberately not `finance.record`.
     */
    public function receive(Request $request, ReturnRequest $return): RedirectResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', 'string', 'in:received,courier_lost'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->receipt->markReceived($return, (int) $request->user()->id, $validated['outcome'], $validated['note'] ?? null);
        } catch (\RuntimeException $e) {
            return redirect()->route('admin.returns.show', $return)->withErrors(['receive' => $e->getMessage()]);
        }

        return redirect()->route('admin.returns.show', $return)->with('status', $validated['outcome'] === 'courier_lost'
            ? 'Recorded as lost by our courier. Points and repurchase credit restored; the refund has been released to the gateway.'
            : 'Return received. Points and repurchase credit restored; the refund has been released to the gateway.');
    }

    /** The buyer never sent the goods back: an explicit, audited decision. */
    public function notReturned(Request $request, ReturnRequest $return): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $this->receipt->markNotReturned($return, (int) $request->user()->id, $validated['reason']);
        } catch (\RuntimeException $e) {
            return redirect()->route('admin.returns.show', $return)->withErrors(['receive' => $e->getMessage()]);
        }

        return redirect()->route('admin.returns.show', $return)->with('status', 'Closed as not returned. The refund is forfeited, the entitlements stay withheld and the order is back to delivered.');
    }

    public function reject(Request $request, ReturnRequest $return): RedirectResponse
    {
        try {
            $this->inspect->reject($return, $request->user()->id);
        } catch (\RuntimeException $e) {
            return redirect()->route('admin.returns.show', $return)->withErrors(['reject' => $e->getMessage()]);
        }

        return redirect()->route('admin.returns.show', $return)->with('status', 'Return rejected. Order reverted to delivered status; customer retains any remaining cooling-off days.');
    }
}
