<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Jobs\RetryRazorpayPayoutJob;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\PayoutReconciliationService;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Support\Csv;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminWeeklyPayoutController extends Controller
{
    public function index(CompensationPlanSettingsService $plan): View
    {
        $batches = PayoutBatch::orderByDesc('batch_date')->paginate(20);
        // Computed here, not in the view: `::class` inside a @php(...) Blade
        // directive fails to compile (unexpected token "class").
        $minPayout = Number::format($plan->minPayoutPaise() / 100, 0);

        return view('admin.compensation.weekly-payouts.index', compact('batches', 'minPayout'));
    }

    public function show(PayoutBatch $batch, PayoutGatewaySettings $settings): View
    {
        $lines = $batch->lineItems()->with('distributor.user')->paginate(50);

        // Counted across the whole batch, not the current page — the header
        // must answer "how much of this batch has actually settled?".
        $statusCounts = $batch->lineItems()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.compensation.weekly-payouts.show', [
            'batch' => $batch,
            'lines' => $lines,
            'statusCounts' => $statusCounts,
            'isRazorpay' => $settings->isRazorpay(),
            'gatewayReady' => $settings->razorpayReady(),
            'maxRetries' => $settings->maxRetries(),
        ]);
    }

    public function approve(Request $request, PayoutBatch $batch, PayoutService $payoutService, PayoutGatewaySettings $settings): RedirectResponse
    {
        if ($batch->status !== PayoutBatch::STATUS_PENDING) {
            return back()->with('error', 'Batch cannot be approved in its current state.');
        }

        // Approving in Razorpay mode initiates real bank transfers. Refusing
        // here — rather than letting the job fail every line item one by one —
        // keeps a misconfigured environment from turning an approval into a
        // batch full of failures that then need retrying.
        if ($settings->isRazorpay() && ! $settings->razorpayReady()) {
            return back()->with('error', 'Razorpay Payouts is selected but its credentials are not configured. Set the RAZORPAYX_* environment variables, or switch the payout gateway to Manual NEFT.');
        }

        $payoutService->approve($batch, (int) $request->user()->id);

        $batch->refresh();

        return redirect()
            ->route('admin.compensation.weekly-payouts.show', $batch)
            ->with('success', $settings->isRazorpay()
                ? 'Batch approved and queued for dispatch. Each transfer is marked transferred only when Razorpay confirms it.'
                : 'Batch approved. Download the NEFT CSV, upload it to the bank, then import the bank’s response file here.');
    }

    /**
     * Manual-NEFT reconciliation: import the bank's response file and settle
     * each line item from it.
     */
    public function reconcile(Request $request, PayoutBatch $batch, PayoutReconciliationService $reconciliation): RedirectResponse
    {
        $request->validate([
            'response_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [], ['response_file' => 'bank response file']);

        if (! in_array($batch->status, [
            PayoutBatch::STATUS_APPROVED,
            PayoutBatch::STATUS_PARTIALLY_FAILED,
            PayoutBatch::STATUS_FAILED,
        ], true)) {
            return back()->with('error', 'Only an approved batch can be reconciled against a bank response file.');
        }

        $summary = $reconciliation->import($batch, $request->file('response_file'), (int) $request->user()->id);

        if ($summary['errors'] !== []) {
            return back()->with('error', implode(' ', $summary['errors']));
        }

        $message = sprintf(
            'Imported %d row(s): %d marked transferred, %d marked failed.',
            $summary['rows'], $summary['transferred'], $summary['failed'],
        );

        if ($summary['unmatched'] !== []) {
            $message .= ' '.count($summary['unmatched']).' row(s) named an ADN that is not in this batch.';
        }
        if ($summary['skipped'] !== []) {
            $message .= ' '.count($summary['skipped']).' row(s) were skipped (already settled or an unrecognised status).';
        }

        return redirect()
            ->route('admin.compensation.weekly-payouts.show', $batch)
            ->with('success', $message);
    }

    /** Re-send every failed line item in the batch that is still under the retry limit. */
    public function retryFailedLineItems(Request $request, PayoutBatch $batch, PayoutGatewaySettings $settings): RedirectResponse
    {
        if (! $settings->isRazorpay()) {
            return back()->with('error', 'Retry is only available while the payout gateway is Razorpay. In Manual NEFT mode, re-export the CSV for the failed lines and import the bank’s response again.');
        }

        $lineIds = $batch->lineItems()
            ->where('status', PayoutLineItem::STATUS_FAILED)
            ->whereNull('razorpay_payout_id')
            ->where('net_transferred_paise', '>', 0)
            ->where('retry_count', '<', $settings->maxRetries())
            ->orderBy('id')
            ->pluck('id');

        if ($lineIds->isEmpty()) {
            return back()->with('error', 'No failed line items in this batch are eligible for retry.');
        }

        foreach ($lineIds as $lineId) {
            RetryRazorpayPayoutJob::dispatch((int) $lineId, (int) $request->user()->id);
        }

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'payout.batch.retry_requested',
            'subject_type' => 'payout_batch',
            'subject_id' => (int) $batch->id,
            'details' => [
                'batch_type' => $batch->batch_type,
                'batch_date' => $batch->batch_date->toDateString(),
                'line_item_count' => $lineIds->count(),
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'Queued '.$lineIds->count().' failed payout(s) for retry.');
    }

    /** Re-send one failed line item. */
    public function retryLineItem(Request $request, PayoutBatch $batch, PayoutLineItem $line, PayoutGatewaySettings $settings): RedirectResponse
    {
        // Route-model binding resolves the line independently of the batch, so
        // the relationship is checked here or a crafted URL could retry any
        // line item from any batch.
        abort_unless((int) $line->payout_batch_id === (int) $batch->id, 404);

        if (! $settings->isRazorpay()) {
            return back()->with('error', 'Retry is only available while the payout gateway is Razorpay.');
        }

        if ($line->status !== PayoutLineItem::STATUS_FAILED) {
            return back()->with('error', 'Only a failed line item can be retried.');
        }

        if ($line->razorpay_payout_id !== null && $line->razorpay_payout_id !== '') {
            return back()->with('error', 'This transfer is already with Razorpay — it cannot be sent again.');
        }

        if ($line->retry_count >= $settings->maxRetries()) {
            return back()->with('error', 'This line item has reached the retry limit ('.$settings->maxRetries().'). Correct the distributor’s bank details before trying again.');
        }

        RetryRazorpayPayoutJob::dispatch((int) $line->id, (int) $request->user()->id);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'payout.line_item.retry_requested',
            'subject_type' => 'payout_line_item',
            'subject_id' => (int) $line->id,
            'details' => [
                'payout_batch_id' => $batch->id,
                'distributor_id' => $line->distributor_id,
                'retry_count' => $line->retry_count,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'Retry queued for ADN '.($line->distributor->adn ?? $line->distributor_id).'.');
    }

    public function exportNeft(PayoutBatch $batch): StreamedResponse
    {
        $lines = $batch->lineItems()
            ->with('distributor.user')
            ->whereIn('status', [
                PayoutLineItem::STATUS_PENDING,
                PayoutLineItem::STATUS_TRANSFERRED,
            ])
            ->orderBy('id')
            ->get();

        $filename = 'neft-batch-'.$batch->batch_date->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($lines): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Line#', 'ADN', 'Full Name', 'Bank Last 4', 'Net Amount (₹)', 'UTR', 'Status',
            ]);
            foreach ($lines as $i => $line) {
                // Every free-text field goes through Csv::safe(). The name is
                // whatever the distributor typed at registration, and this is
                // the file that gets opened in Excel and handed to the bank —
                // a cell beginning `=` is a formula there, not a name.
                fputcsv($out, [
                    $i + 1,
                    Csv::safe($line->distributor->adn ?? ''),
                    Csv::safe($line->distributor->user?->full_name ?? ''),
                    Csv::safe($line->bank_account_last4 ?? ''),
                    number_format($line->net_transferred_paise / 100, 2, '.', ''),
                    Csv::safe($line->utr_number ?? ''),
                    Csv::safe($line->status),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
