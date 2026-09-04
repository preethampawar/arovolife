<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Payments\Data\ConfirmationResult;
use App\Modules\Payments\Jobs\SendRazorpayRefundJob;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Payments\Services\RazorpayRefundService;
use App\Modules\Payments\Support\RefundWorklist;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
    ) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');
        $gateway = (string) $request->query('gateway', '');
        $q = trim((string) $request->query('q', ''));

        $intents = PaymentIntent::query()
            ->with(['order.customer'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($gateway !== '', fn ($query) => $query->where('gateway', $gateway))
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(function ($inner) use ($q): void {
                    $inner->where('gateway_order_id', $q)
                        ->orWhere('gateway_payment_id', $q)
                        ->orWhereHas('order', fn ($o) => $o->where('order_no', $q));
                });
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $statusCounts = PaymentIntent::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        return view('admin.payments.index', [
            'intents' => $intents,
            'statusCounts' => $statusCounts,
            'filters' => ['status' => $status, 'gateway' => $gateway, 'q' => $q],
            'attention' => $this->worklist->attentionCount(),
        ]);
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
            'before_hash' => hash('sha256', $before),
            'after_hash' => hash('sha256', $intent->fresh()->status),
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
            'classify' => fn (RefundIntent $r): array => $this->worklist->classify($r),
        ]);
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
            'before_hash' => hash('sha256', $before),
            'after_hash' => hash('sha256', RefundIntent::STATUS_CREATED),
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

        $this->refunds->settleManually($refund, (int) $request->user()->id, $validated['reference'], $validated['note'] ?? null);

        return redirect()->route('admin.payments.refunds')->with('status', 'Manual settlement recorded against the settlement bank account; the refund payable is discharged and the order is marked refunded.');
    }
}
