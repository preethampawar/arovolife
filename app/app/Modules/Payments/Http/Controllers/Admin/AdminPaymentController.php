<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Payments\Data\ConfirmationResult;
use App\Modules\Payments\Jobs\SendRazorpayRefundJob;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Payments\Services\RazorpayRefundService;
use App\Modules\Payments\Support\InvoiceGapWorklist;
use App\Modules\Payments\Support\RefundPayable;
use App\Modules\Payments\Support\RefundWorklist;
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\ListFilters;
use App\Modules\Tax\Services\InvoiceGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Admin → Payments: every gateway intent, its full event timeline, and the
 * unsettled-refunds worklist. Reading is `audit.read` (monitoring, held by
 * every scoped role); the two actions that move or create money — sync,
 * which may mark an order paid, and refund retry / manual settlement — are
 * `finance.record` (R-17). Each writes an audit row with the actor.
 */
final class AdminPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentConfirmationService $confirmation,
        private readonly RazorpayRefundService $refunds,
        private readonly RefundWorklist $worklist,
        private readonly InvoiceGapWorklist $invoiceGaps,
        private readonly InvoiceGenerator $invoices,
    ) {}

    public function index(Request $request): View
    {
        $filters = ListFilters::make($request, [
            FilterField::select('status', 'Status', [
                PaymentIntent::STATUS_CREATED => 'Awaiting payment',
                PaymentIntent::STATUS_AUTHORISED => 'Authorised',
                PaymentIntent::STATUS_CAPTURED => 'Captured',
                PaymentIntent::STATUS_FAILED => 'Failed',
                PaymentIntent::STATUS_CANCELLED => 'Cancelled / expired',
            ], column: 'payment_intents.status', placeholder: 'All statuses'),
            FilterField::select('gateway', 'Gateway', [
                PaymentIntent::GATEWAY_RAZORPAY => 'Razorpay',
                PaymentIntent::GATEWAY_STUB => 'Stub (dev)',
            ], column: 'payment_intents.gateway', placeholder: 'Any gateway'),
            FilterField::text('q', 'Search', 'Order no, order_… or pay_…'),
            FilterField::dateRange('created', 'Created', dateColumn: 'payment_intents.created_at'),
        ]);

        $q = $filters->value('q');

        $intents = $filters->apply(
            PaymentIntent::query()
                ->with(['order.customer'])
                ->when($q !== null, function ($query) use ($q): void {
                    $query->where(function ($inner) use ($q): void {
                        $inner->where('gateway_order_id', $q)
                            ->orWhere('gateway_payment_id', $q)
                            ->orWhereHas('order', fn ($o) => $o->where('order_no', $q));
                    });
                })
        )
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Restored alongside the toolbar: the status facets are how this page
        // is actually used day to day — one click to "what is stuck awaiting
        // payment", with the size of the queue visible before you click.
        $statusCounts = PaymentIntent::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        return view('admin.payments.index', [
            'intents' => $intents,
            'statusCounts' => $statusCounts,
            'filters' => $filters,
            'attention' => $this->worklist->attentionCount(),
            'invoiceGaps' => $this->invoiceGaps->orders(),
            'invoiceGapCount' => $this->invoiceGaps->count(),
        ]);
    }

    /**
     * Issue the invoice a confirmed payment failed to produce, allocating the
     * next consecutive number under the generator's own lock.
     *
     * Issues only where there is nothing to issue. This is not a reissue and
     * cannot become one: an invoice already raised is a statutory document,
     * and correcting it means a §34 credit note and a fresh invoice, never an
     * overwrite.
     */
    public function generateInvoice(Request $request, Order $order): RedirectResponse
    {
        if ($order->paid_at === null) {
            return back()->withErrors(['invoice' => 'This order has not been paid; an invoice is issued only on payment.']);
        }

        try {
            $invoice = $this->invoices->generate($order);
        } catch (Throwable $e) {
            Log::channel('payments')->error('manual invoice generation failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['invoice' => 'Invoice generation failed again: '.$e->getMessage()]);
        }

        // `generate()` is idempotent — it hands back an existing invoice rather
        // than issuing a second one — so pressing this twice used to write
        // `invoice.generated_manually` with a `no_invoice` before-state either
        // way: an audit row asserting an issue that never happened, naming a
        // pre-existing invoice as the new one.
        //
        // The generator now answers the question itself, inside the same
        // transaction that allocates the number. A check here before calling
        // would read the same "no invoice" under two simultaneous presses and
        // still write the false row for whichever lost.
        if (! $invoice->wasRecentlyCreated) {
            return back()->with('status', "Order {$order->order_no} already carries invoice {$invoice->invoice_no}. Nothing was issued — an invoice is never replaced in place; a wrong one needs a credit note.");
        }

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'invoice.generated_manually',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'before_hash' => AuditLog::digest('no_invoice'),
            'after_hash' => AuditLog::digest((string) $invoice->invoice_no),
            'details' => ['order_no' => $order->order_no, 'invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', "Invoice {$invoice->invoice_no} issued for {$order->order_no}.");
    }

    public function show(PaymentIntent $intent): View
    {
        $intent->load(['order.customer', 'order.items']);

        $events = PaymentEvent::query()
            ->where(fn ($q) => $q->where('payment_intent_id', $intent->id)->orWhere('order_id', $intent->order_id))
            ->orderBy('id')
            ->get();

        $refunds = RefundIntent::where('order_id', $intent->order_id)->orderBy('id')->get();

        return view('admin.payments.show', [
            'intent' => $intent,
            'events' => $events,
            'refunds' => $refunds,
            'classify' => fn (RefundIntent $r): array => $this->worklist->classify($r),
        ]);
    }

    /** Ask the gateway now. May mark the order paid — a staff action that creates BV, so audited with the actor. */
    public function sync(Request $request, PaymentIntent $intent): RedirectResponse
    {
        $before = $intent->status;

        try {
            $result = $this->confirmation->syncAndConfirm($intent, PaymentIntent::CONFIRMED_VIA_ADMIN, (int) $request->user()->id);
        } catch (Throwable $e) {
            return redirect()->route('admin.payments.show', $intent)->withErrors(['sync' => 'The gateway could not be asked: '.$e->getMessage()]);
        }

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'payment.synced_by_admin',
            'subject_type' => 'payment_intent',
            'subject_id' => $intent->id,
            'before_hash' => AuditLog::digest($before),
            'after_hash' => AuditLog::digest($intent->fresh()->status),
            'details' => ['order_id' => $intent->order_id, 'result' => $result->status, 'message' => $result->message, 'gateway_payment_id' => $intent->fresh()->gateway_payment_id],
            'ip' => $request->ip(),
        ]);

        $message = match ($result->status) {
            ConfirmationResult::CONFIRMED => 'Confirmed: the gateway reports this payment captured and the order is now paid.',
            ConfirmationResult::ALREADY_CONFIRMED => 'Already confirmed — nothing changed.',
            ConfirmationResult::FAILED => 'The gateway reports the last attempt failed. The order stays placed.',
            ConfirmationResult::LATE_CAPTURE => 'The gateway reports a capture on an order that was already cancelled; a full refund has been queued.',
            default => 'No capture yet — the gateway reports the payment as pending or not attempted.',
        };

        return redirect()->route('admin.payments.show', $intent)->with('status', $message);
    }

    public function refunds(): View
    {
        $refunds = $this->worklist->outstandingRefunds();

        return view('admin.payments.refunds', [
            'refunds' => $refunds,
            'awaitingReceipt' => $this->worklist->awaitingReceipt(),
            'manualRefunds' => $this->worklist->manualRefunds(),
            'owed' => fn (Order $o): int => RefundPayable::owedOutsideGateway($o),
            'classify' => fn (RefundIntent $r): array => $this->worklist->classify($r),
        ]);
    }

    /** A refund with no gateway payment behind it (R-68): finance made the NEFT, records it here. */
    public function settleOrderRefund(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'min:6', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->refunds->settleOrderManually($order, (int) $request->user()->id, $validated['reference'], $validated['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return redirect()->route('admin.payments.refunds')->with('status', 'Manual settlement recorded against the settlement bank account; the refund payable is discharged and the order is marked refunded.');
    }

    /** Re-drive the SAME refund intent — never a new one. */
    public function retryRefund(Request $request, RefundIntent $refund): RedirectResponse
    {
        if ($refund->status === RefundIntent::STATUS_PROCESSED) {
            return back()->withErrors(['refund' => 'This refund is already settled.']);
        }
        if ($refund->isHeld()) {
            return back()->withErrors(['refund' => 'This refund is held until the return is received; release it from the return, not here.']);
        }
        if ($refund->isForfeited()) {
            return back()->withErrors(['refund' => 'This refund was forfeited — the goods never came back. Nothing is owed and it cannot be re-sent.']);
        }

        $before = $refund->status;
        $refund->update([
            'status' => RefundIntent::STATUS_CREATED,
            'failed_at' => null,
            'error_code' => null,
            'error_description' => null,
        ]);
        SendRazorpayRefundJob::dispatch($refund->id);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'refund.retried',
            'subject_type' => 'refund_intent',
            'subject_id' => $refund->id,
            'before_hash' => AuditLog::digest($before),
            'after_hash' => AuditLog::digest(RefundIntent::STATUS_CREATED),
            'details' => ['order_id' => $refund->order_id, 'amount_paise' => $refund->amount_paise],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.payments.refunds')->with('status', 'Refund re-queued for the gateway. It re-drives the same refund, so a duplicate cannot be created.');
    }

    /** Finance paid the buyer by NEFT; record it and discharge the payable. */
    public function settleRefund(Request $request, RefundIntent $refund): RedirectResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'min:6', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($refund->status === RefundIntent::STATUS_PROCESSED) {
            return back()->withErrors(['refund' => 'This refund is already settled.']);
        }

        try {
            $this->refunds->settleManually($refund, (int) $request->user()->id, $validated['reference'], $validated['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return redirect()->route('admin.payments.refunds')->with('status', 'Manual settlement recorded against the settlement bank account; the refund payable is discharged and the order is marked refunded.');
    }
}
