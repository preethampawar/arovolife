<?php

declare(strict_types=1);

namespace App\Modules\Returns\Http\Controllers\Admin;

use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\InspectReturn;
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
    public function __construct(private readonly InspectReturn $inspect) {}

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
            ->with('status', "Refund approved for order {$orderNo}. Ledger reversed and BV reversed. Customer will receive refund within 7 working days (Phase-2 stub — gateway settlement is Phase 3).");
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
