<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin\Concerns;

use App\Modules\Compensation\Exceptions\BankDecryptionException;
use App\Modules\Compensation\Jobs\RetryRazorpayPayoutJob;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\PayoutReconciliationService;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Shared\Support\Csv;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything an admin does TO a payout batch: approve it, reconcile the bank's
 * response, retry a failed transfer, export the NEFT file.
 *
 * None of it differs between the weekly and the monthly batch except where the
 * admin is sent afterwards, so it lives here rather than in one controller that
 * the other's pages then had to post to. They did: every button on the monthly
 * batch page submitted to `weekly-payouts/*`, which worked only because the
 * weekly routes never checked the batch type, and bounced the admin onto the
 * weekly page afterwards (QA F97).
 */
trait HandlesPayoutBatchActions
{
    /** The route name for `$action` within the section this controller serves. */
    abstract protected function payoutRouteName(string $action): string;

    /**
     * The income this batch looked at and did NOT move: web-only, KYC-pending,
     * no bank account, undecryptable bank details.
     *
     * The batch's own `total_net_paise` and `distributor_count` cover the paying
     * lines alone, so a batch of nothing but holds asked an admin to confirm
     * "₹0.00 to 0 distributor(s)" while sitting on tens of thousands of rupees
     * of held income (QA F97). The confirmation says both figures now.
     *
     * @return array{gross: int, count: int}
     */
    protected function heldTotals(PayoutBatch $batch): array
    {
        $held = $batch->lineItems()
            ->whereIn('status', PayoutLineItem::HELD_STATUSES)
            ->selectRaw('COALESCE(SUM(gross_paise), 0) AS gross, COUNT(*) AS cnt')
            ->first();

        return [
            'gross' => (int) ($held?->getAttribute('gross') ?? 0),
            'count' => (int) ($held?->getAttribute('cnt') ?? 0),
        ];
    }

    public function approve(Request $request, PayoutBatch $batch, PayoutService $payoutService, PayoutGatewaySettings $settings): RedirectResponse
    {
        if ($batch->status !== PayoutBatch::STATUS_PENDING) {
            return back()->with('error', 'Batch cannot be approved in its current state.');
        }

        // Maker-checker (QA F94). The route already restricts approval to
        // `finance.approve`, which `finance.record` — the role that runs the
        // batch, imports the bank response and retries a transfer — does not
        // hold. This is the second half: even a holder of both may not sign off
        // a batch they themselves created. A batch the scheduler built carries
        // no maker (`created_by` NULL) and any approver may check it.
        $actorId = (int) $request->user()->id;

        if ($batch->created_by !== null && (int) $batch->created_by === $actorId) {
            AuditLog::create([
                'actor_id' => $actorId,
                'action' => 'payout.batch.self_approval_refused',
                'subject_type' => 'payout_batch',
                'subject_id' => (int) $batch->id,
                // A refusal moves nothing: the batch stands exactly as it was,
                // and the matching digests say so.
                'before_hash' => AuditDigests::of($batch),
                'after_hash' => AuditDigests::of($batch),
                'details' => [
                    'batch_type' => $batch->batch_type,
                    'batch_date' => $batch->batch_date->toDateString(),
                    'created_by' => (int) $batch->created_by,
                    'total_net_paise' => $batch->total_net_paise,
                ],
                'ip' => $request->ip(),
            ]);

            return back()->with('error', 'You created this batch, so you cannot also approve it. Separation of duties requires a second person to sign a payout batch off.');
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
            ->route($this->payoutRouteName('show'), $batch)
            ->with('success', $settings->isRazorpay()
                ? 'Batch approved and queued for dispatch. Each transfer is marked transferred only when Razorpay confirms it.'
                : 'Batch approved. Download the bank file (NEFT), upload it to the bank, then import the bank’s response file here.');
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
        if ($summary['rejected'] !== []) {
            $message .= ' '.count($summary['rejected']).' row(s) were REJECTED and not applied: '
                .implode('; ', array_slice($summary['rejected'], 0, 5))
                .(count($summary['rejected']) > 5 ? '; …' : '').'.';
        }
        if (! $summary['amount_checked']) {
            $message .= ' The file carried no amount column, so amounts were not verified against this batch.';
        }

        return redirect()
            ->route($this->payoutRouteName('show'), $batch)
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
            // Queueing moves nothing yet; the after digest pins which lines
            // were authorised for another attempt.
            'before_hash' => AuditDigests::of($batch),
            'after_hash' => AuditDigests::of(['line_item_ids' => $lineIds->all()]),
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
            // The retry job moves the line, not this request; the digests pin
            // the state it was authorised against.
            'before_hash' => AuditDigests::of($line),
            'after_hash' => AuditDigests::of($line),
            'details' => [
                'payout_batch_id' => $batch->id,
                'distributor_id' => $line->distributor_id,
                'retry_count' => $line->retry_count,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'Retry queued for ADN '.($line->distributor->adn ?? $line->distributor_id).'.');
    }

    /**
     * Deliberately still CSV after the 2026-09-12 XLSX migration: this file is
     * uploaded to the bank's portal, which parses CSV. It is not a report and
     * no person opens it in Excel. Do not "finish the job" by converting it —
     * a rejected bank upload is a stalled payout run.
     */
    /**
     * The instruction file finance uploads to the bank.
     *
     * Restricted to `finance.record` and to a batch that has actually been
     * signed off. It used to be readable by every admin role and to download a
     * header-only file for a batch nobody had approved, which is a payment
     * instruction for money no one authorised — and, silently, an export of
     * distributor names and bank digits to roles with no reason to hold them
     * (QA F95).
     *
     * It is now a file the bank can actually execute (QA F44/F95, client
     * decision 2026-09-11): full account number, IFSC and the beneficiary name
     * the bank holds, plus a narration carrying the ADN and the batch so the
     * distributor can recognise the credit on their statement. That makes the
     * download itself a disclosure of every payee's bank account, so each one
     * writes a `payout.batch.bank_file_exported` audit row carrying a SHA-256
     * of the exact bytes handed over — enough to prove later which file went to
     * the bank, without the file or any account number being stored anywhere.
     */
    public function exportNeft(Request $request, PayoutBatch $batch, PayoutService $payoutService): StreamedResponse|RedirectResponse
    {
        if (! in_array($batch->status, [
            PayoutBatch::STATUS_APPROVED,
            PayoutBatch::STATUS_DISPATCHED,
            PayoutBatch::STATUS_COMPLETED,
            PayoutBatch::STATUS_PARTIALLY_FAILED,
            PayoutBatch::STATUS_FAILED,
        ], true)) {
            return back()->with('error', 'This batch has not been approved yet. Approve it first — the NEFT file is the instruction the bank acts on, and it must not exist before finance has signed the amount off.');
        }

        /** @var EloquentCollection<int, PayoutLineItem> $lines */
        $lines = $batch->lineItems()
            ->with('distributor.user')
            ->whereIn('status', [
                PayoutLineItem::STATUS_PENDING,
                PayoutLineItem::STATUS_TRANSFERRED,
            ])
            ->orderBy('id')
            ->get();

        // Built in full before anything is sent: the audit row has to carry a
        // digest of the bytes the admin actually received, which cannot be
        // known while they are still being streamed.
        $csv = $this->buildBankFile($batch, $lines, $payoutService);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'payout.batch.bank_file_exported',
            'subject_type' => 'payout_batch',
            'subject_id' => (int) $batch->id,
            // An export moves nothing, so there is no before-state.
            'before_hash' => null,
            // Raw 32 bytes, never hex — the column is BINARY(32).
            'after_hash' => AuditLog::digest($csv),
            'details' => [
                'batch_type' => $batch->batch_type,
                'batch_date' => $batch->batch_date->toDateString(),
                'line_count' => $lines->count(),
                'total_net_paise' => $batch->total_net_paise,
                // Deliberately no account numbers, no names, no file body.
                'digest_algorithm' => 'sha256',
            ],
            'ip' => $request->ip(),
        ]);

        $filename = 'bank-upload-'.$batch->batch_type.'-'.$batch->batch_date->format('Y-m-d').'.csv';

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * {@see exportNeft()} — the file itself, as a string.
     *
     * @param  EloquentCollection<int, PayoutLineItem>  $lines
     */
    private function buildBankFile(PayoutBatch $batch, EloquentCollection $lines, PayoutService $payoutService): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, [
            'Line#', 'ADN', 'Beneficiary Name', 'Account Number', 'IFSC',
            'Net Amount (₹)', 'Narration', 'UTR', 'Status',
        ]);

        foreach ($lines as $i => $line) {
            $adn = (string) ($line->distributor->adn ?? '');
            $status = $line->status;

            try {
                $bank = $payoutService->bankInstructionForDistributor((int) $line->distributor_id);
            } catch (BankDecryptionException) {
                // The ciphertext opened when the batch was built and does not
                // open now (a key rotation between the two). The line stays in
                // the file so finance can see who is unpaid and why, but with
                // no account number it cannot be executed by mistake.
                $bank = [
                    'account_number' => '',
                    'ifsc' => '',
                    'beneficiary_name' => (string) $line->distributor->user?->full_name,
                ];
                $status = PayoutLineItem::STATUS_BANK_DECRYPT_FAILED;
            }

            // Every free-text field goes through Csv::safe(). This is the file
            // that gets opened in Excel and handed to the bank — a cell
            // beginning `=` is a formula there, not a name or an account.
            fputcsv($handle, [
                $i + 1,
                Csv::safe($adn),
                Csv::safe($bank['beneficiary_name']),
                Csv::safe($bank['account_number']),
                Csv::safe($bank['ifsc']),
                number_format($line->net_transferred_paise / 100, 2, '.', ''),
                Csv::safe($this->narrationFor($adn, $batch)),
                Csv::safe($line->utr_number ?? ''),
                Csv::safe($status),
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * What the distributor sees on their bank statement, and what finance
     * reconciles the bank's response against: the payer, the ADN being paid and
     * the batch that paid it. Kept short — NEFT remittance information is
     * truncated well before a sentence fits.
     */
    private function narrationFor(string $adn, PayoutBatch $batch): string
    {
        return 'arovolife '.$adn.' B'.$batch->id;
    }
}
