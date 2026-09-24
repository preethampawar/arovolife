<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin\Concerns;

use App\Modules\Compensation\Exceptions\BankDecryptionException;
use App\Modules\Compensation\Exceptions\PayoutLineActionRefused;
use App\Modules\Compensation\Models\PayoutBankFile;
use App\Modules\Compensation\Models\PayoutBankFileRow;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutBankFileDiffService;
use App\Modules\Compensation\Services\PayoutBankFileVault;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\PayoutLineSettlementService;
use App\Modules\Compensation\Services\PayoutReconciliationService;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Shared\Support\Csv;
use App\Modules\Shared\Support\ListFilters;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
    use RefusesProjectedFigures;

    /** The route name for `$action` within the section this controller serves. */
    abstract protected function payoutRouteName(string $action): string;

    /**
     * The filter set of this section's batch list. Defined once per controller
     * and used by both the page and its download, so a filter the operator set
     * on screen means the same thing in the file they take away from it.
     */
    abstract protected function payoutBatchFilters(Request $request): ListFilters;

    /**
     * This section's batch list before any filter: which batch types it lists,
     * plus the held-line count the page and the download both report.
     *
     * @return Builder<PayoutBatch>
     */
    abstract protected function payoutBatchQuery(): Builder;

    /**
     * The batch list as a spreadsheet — the same rows, in the same order, under
     * the filters in force, with the deduction total broken into the three
     * parts finance reconciles against (repurchase, admin charge, TDS).
     *
     * Unpaginated by design: the point of the download is the whole filtered
     * list. It carries no bank details and no distributor names — it is a list
     * of batches, not of payees; the payees are the bank file's business, which
     * stays gated on `finance.record`.
     */
    protected function exportBatchList(Request $request, string $filename): StreamedResponse
    {
        /** @var EloquentCollection<int, PayoutBatch> $batches */
        $batches = $this->payoutBatchFilters($request)
            ->apply($this->payoutBatchQuery())
            ->orderByDesc('batch_date')
            ->get();

        $parts = $this->deductionTotalsFor(
            array_values(array_map(static fn (PayoutBatch $b): int => (int) $b->id, $batches->all()))
        );

        $columns = [
            ['key' => 'sno', 'label' => 'S.No.'],
            ['key' => 'batch_date', 'label' => 'Batch Date'],
            ['key' => 'batch_type', 'label' => 'Type'],
            ['key' => 'earnings_through', 'label' => 'Earnings Through'],
            ['key' => 'distributors', 'label' => 'Distributors Paid'],
            ['key' => 'held', 'label' => 'Distributors Held'],
            ['key' => 'gross', 'label' => 'Total Gross (Rs)'],
            ['key' => 'repurchase', 'label' => 'Repurchase Deduction (Rs)'],
            ['key' => 'admin_charge', 'label' => 'Admin Charge (Rs)'],
            ['key' => 'tds', 'label' => 'TDS (Rs)'],
            ['key' => 'deductions', 'label' => 'Total Deductions (Rs)'],
            ['key' => 'net', 'label' => 'Net Transferred (Rs)'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'approved_at', 'label' => 'Approved At'],
            ['key' => 'processed_at', 'label' => 'Processed At'],
        ];

        $rows = $batches->values()->map(function (PayoutBatch $batch, int $i) use ($parts): array {
            $part = $parts[(int) $batch->id] ?? ['repurchase' => 0, 'admin_charge' => 0, 'tds' => 0];

            return [
                'sno' => $i + 1,
                'batch_date' => $batch->batch_date->toDateString(),
                'batch_type' => str_replace('_', ' ', $batch->batch_type),
                // Null on monthly, legacy and pre-column batches: they swept
                // the whole wallet balance instead of one earning week.
                'earnings_through' => $batch->earnings_through?->toDateString() ?? '',
                'distributors' => $batch->distributor_count,
                'held' => (int) ($batch->getAttribute('held_count') ?? 0),
                'gross' => $batch->total_gross_paise / 100,
                'repurchase' => $part['repurchase'] / 100,
                'admin_charge' => $part['admin_charge'] / 100,
                'tds' => $part['tds'] / 100,
                'deductions' => $batch->total_deductions_paise / 100,
                'net' => $batch->total_net_paise / 100,
                'status' => str_replace('_', ' ', $batch->status),
                'approved_at' => $batch->approved_at?->format('Y-m-d H:i') ?? '',
                'processed_at' => $batch->processed_at?->format('Y-m-d H:i') ?? '',
            ];
        })->all();

        return ReportExport::respond($request, $filename.'-'.now()->toDateString(), $columns, $rows);
    }

    /**
     * The bank column of the batch's line-item table: the full account number
     * per distributor id, so finance can read the digits off the screen while
     * verifying them against the bank (client decision 2026-09-20), instead of
     * a last-4 that no beneficiary can be confirmed from.
     *
     * Gated on `finance.record`, the same authority that may pull the NEFT
     * file. The batch page itself is visible to admin-operations and
     * admin-compliance, who deliberately may not export that file — showing
     * them every payee's account number here would hand back exactly the
     * disclosure QA F95 closed. They keep the stored last-4.
     *
     * A reveal is a disclosure of the whole batch's bank details, so it writes
     * the same kind of audit row the export does — counts and the page only,
     * never a number.
     *
     * @param  LengthAwarePaginator<int, Model>  $lines
     * @return array{full: bool, numbers: array<int, string>}
     */
    protected function bankAccountColumn(
        Request $request,
        PayoutBatch $batch,
        LengthAwarePaginator $lines,
        PayoutService $payoutService,
    ): array {
        if (! Gate::allows('finance.record')) {
            return ['full' => false, 'numbers' => []];
        }

        $numbers = $payoutService->bankAccountNumbersForDistributors(
            array_values(array_map(
                static fn ($id): int => (int) $id,
                $lines->getCollection()->pluck('distributor_id')->all()
            ))
        );

        if ($numbers !== []) {
            AuditLog::create([
                'actor_id' => $request->user()?->id,
                'action' => 'payout.batch.bank_accounts_viewed',
                'subject_type' => 'payout_batch',
                'subject_id' => (int) $batch->id,
                // Nothing moves on a read, so there is no before-state.
                'before_hash' => null,
                'after_hash' => null,
                'details' => [
                    'batch_type' => $batch->batch_type,
                    'batch_date' => $batch->batch_date->toDateString(),
                    'page' => $lines->currentPage(),
                    'revealed_count' => count($numbers),
                    // Deliberately no account numbers and no names.
                ],
                'ip' => $request->ip(),
            ]);
        }

        return ['full' => true, 'numbers' => $numbers];
    }

    /**
     * The three parts the batch's `total_deductions_paise` is made of, summed
     * over every line item — held ones included, exactly as
     * PayoutService::finalizeBatchTotals() sums the stored total, so the
     * breakdown always adds back up to the figure on the card.
     *
     * @return array{repurchase: int, admin_charge: int, tds: int}
     */
    protected function deductionTotals(PayoutBatch $batch): array
    {
        return $this->deductionTotalsFor([(int) $batch->id])[(int) $batch->id]
            ?? ['repurchase' => 0, 'admin_charge' => 0, 'tds' => 0];
    }

    /**
     * {@see deductionTotals()} for a list of batches at once, keyed by batch
     * id — one grouped query, so the batch-list download does not run three
     * sums per row.
     *
     * @param  list<int>  $batchIds
     * @return array<int, array{repurchase: int, admin_charge: int, tds: int}>
     */
    protected function deductionTotalsFor(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        return PayoutLineItem::whereIn('payout_batch_id', $batchIds)
            ->groupBy('payout_batch_id')
            ->selectRaw(
                'payout_batch_id, '
                .'COALESCE(SUM(repurchase_deduction_paise), 0) AS repurchase, '
                .'COALESCE(SUM(admin_charge_paise), 0) AS admin_charge, '
                .'COALESCE(SUM(tds_paise), 0) AS tds'
            )
            ->get()
            ->mapWithKeys(static fn (PayoutLineItem $row): array => [
                (int) $row->getAttribute('payout_batch_id') => [
                    'repurchase' => (int) $row->getAttribute('repurchase'),
                    'admin_charge' => (int) $row->getAttribute('admin_charge'),
                    'tds' => (int) $row->getAttribute('tds'),
                ],
            ])
            ->all();
    }

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

        if (($projected = $this->projectedFiguresRefusal(
            'The amounts in this batch were computed on a clock that has not arrived, so they cannot be approved or sent to a bank.'
        )) !== null) {
            return back()->with('error', $projected);
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

        if ($batch->approved_at === null || ! in_array($batch->status, [
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

    /**
     * Send all failed again: every failed line in the batch goes back in front
     * of the bank.
     *
     * Manual NEFT: each returns to waiting and is in the next bank file.
     * Razorpay: each line under the retry limit is re-dispatched; one whose
     * earlier payout Razorpay does not confirm as dead is skipped and named.
     */
    public function retryFailedLineItems(Request $request, PayoutBatch $batch, PayoutLineSettlementService $settlement, PayoutGatewaySettings $settings): RedirectResponse
    {
        if (($refusal = $this->lineActionRefusal($batch)) !== null) {
            return back()->with('error', $refusal);
        }

        $query = $batch->lineItems()
            ->where('status', PayoutLineItem::STATUS_FAILED)
            ->where('net_transferred_paise', '>', 0);

        $needsCheck = collect();
        if ($settings->isRazorpay()) {
            $query->where('retry_count', '<', $settings->maxRetries());

            // A line that still holds a payout id needs Razorpay to confirm the
            // old transfer is dead first — one API call per line, which a bulk
            // click must not make inside a web request. Those are named, and
            // sent one by one with Send again.
            $needsCheck = (clone $query)->whereNotNull('razorpay_payout_id')->where('razorpay_payout_id', '!=', '')
                ->with('distributor')->get();
            $query->where(fn ($q) => $q->whereNull('razorpay_payout_id')->orWhere('razorpay_payout_id', ''));
        }

        /** @var EloquentCollection<int, PayoutLineItem> $lines */
        $lines = $query->with('distributor')->orderBy('id')->get();

        if ($lines->isEmpty() && $needsCheck->isNotEmpty()) {
            return back()->with('error', 'Each failed line here was reported failed by Razorpay itself. Use Send again on each line — Razorpay is asked to confirm the earlier transfer is dead before a new one is sent.');
        }

        if ($lines->isEmpty()) {
            return back()->with('error', $settings->isRazorpay()
                ? 'No failed line in this batch can be sent again: each has reached the retry limit ('.$settings->maxRetries().'). Use Send again on a line after correcting its bank details.'
                : 'No failed line in this batch has anything to send.');
        }

        $actorId = (int) $request->user()->id;
        $sent = [];
        $refused = [];

        foreach ($lines as $line) {
            try {
                $settlement->sendAgain($line, $actorId, respectRetryLimit: true);
                $sent[] = (int) $line->id;
            } catch (PayoutLineActionRefused $e) {
                $refused[] = ($line->distributor->adn ?? $line->distributor_id).' ('.$e->getMessage().')';
            }
        }

        AuditLog::create([
            'actor_id' => $actorId,
            'action' => 'payout.batch.retry_requested',
            'subject_type' => 'payout_batch',
            'subject_id' => (int) $batch->id,
            // Each line wrote its own sent_again row; this one pins which lines
            // the bulk click covered.
            'before_hash' => AuditDigests::of($batch),
            'after_hash' => AuditDigests::of(['line_item_ids' => $sent]),
            'details' => [
                'batch_type' => $batch->batch_type,
                'batch_date' => $batch->batch_date->toDateString(),
                'gateway' => $settings->gateway(),
                'line_item_count' => count($sent),
                'refused_count' => count($refused),
            ],
            'ip' => $request->ip(),
        ]);

        $message = $settings->isRazorpay()
            ? count($sent).' failed payout(s) sent to Razorpay again.'
            : count($sent).' failed payout(s) are waiting for the bank again. Download the bank file — it holds only the lines still to pay — and upload it to the bank.';

        foreach ($needsCheck as $line) {
            $refused[] = ($line->distributor->adn ?? $line->distributor_id).' (reported failed by Razorpay — use Send again on the line)';
        }

        if ($refused !== []) {
            $message .= ' Not sent: '.implode('; ', array_slice($refused, 0, 5)).(count($refused) > 5 ? '; …' : '').'.';
        }

        return redirect()->route($this->payoutRouteName('show'), $batch)->with($sent === [] ? 'error' : 'success', $message);
    }

    /** Send again: put one failed line back in front of the bank. */
    public function retryLineItem(Request $request, PayoutBatch $batch, PayoutLineItem $line, PayoutLineSettlementService $settlement, PayoutGatewaySettings $settings): RedirectResponse
    {
        return $this->lineAction($batch, $line, function () use ($request, $line, $settlement, $settings): string {
            $settlement->sendAgain($line, (int) $request->user()->id);

            return $settings->isRazorpay()
                ? 'Sent to Razorpay again for ADN '.$this->adnOf($line).'.'
                : 'ADN '.$this->adnOf($line).' is waiting for the bank again. Download the bank file and upload it to the bank.';
        });
    }

    /** Mark paid: the bank confirmed this transfer outside the response file. */
    public function markLinePaid(Request $request, PayoutBatch $batch, PayoutLineItem $line, PayoutLineSettlementService $settlement): RedirectResponse
    {
        $data = $request->validate([
            'utr' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
        ], ['utr.regex' => 'The UTR may contain letters and digits only.'], ['utr' => 'bank reference (UTR)']);

        return $this->lineAction($batch, $line, function () use ($request, $line, $settlement, $data): string {
            $settlement->markPaid($line, (string) $data['utr'], (int) $request->user()->id);

            return 'ADN '.$this->adnOf($line).' marked paid with UTR '.strtoupper(trim((string) $data['utr'])).'.';
        });
    }

    /** Mark failed: the bank reported this transfer failed outside the response file. */
    public function markLineFailed(Request $request, PayoutBatch $batch, PayoutLineItem $line, PayoutLineSettlementService $settlement): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'reason']);

        return $this->lineAction($batch, $line, function () use ($request, $line, $settlement, $data): string {
            $settlement->markFailed($line, (string) $data['reason'], (int) $request->user()->id);

            return 'ADN '.$this->adnOf($line).' marked failed. Use Send again once the cause is fixed.';
        });
    }

    /** Mark returned: the bank sent back a transfer already recorded as paid. */
    public function markLineReturned(Request $request, PayoutBatch $batch, PayoutLineItem $line, PayoutLineSettlementService $settlement): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'reason']);

        return $this->lineAction($batch, $line, function () use ($request, $line, $settlement, $data): string {
            $settlement->markReturned($line, (string) $data['reason'], (int) $request->user()->id);

            return 'ADN '.$this->adnOf($line).' marked returned — the line is failed again. Use Send again once the cause is fixed.';
        });
    }

    /** Check with Razorpay: ask where a transfer stands when its webhook never came. */
    public function checkLineWithGateway(Request $request, PayoutBatch $batch, PayoutLineItem $line, PayoutLineSettlementService $settlement): RedirectResponse
    {
        return $this->lineAction($batch, $line, function () use ($request, $line, $settlement): string {
            $outcome = $settlement->checkWithRazorpay($line, (int) $request->user()->id);

            return match ($outcome) {
                PayoutLineItem::STATUS_TRANSFERRED => 'Razorpay confirms ADN '.$this->adnOf($line).' was paid. The line is marked paid.',
                PayoutLineItem::STATUS_FAILED => 'Razorpay reports ADN '.$this->adnOf($line).' as failed. The line is marked failed — use Send again once the cause is fixed.',
                'unchanged' => 'ADN '.$this->adnOf($line).' was already settled while Razorpay was being asked. Nothing changed.',
                default => 'Razorpay still has ADN '.$this->adnOf($line).' in progress ("'.$outcome.'"). Nothing changed — check again later.',
            };
        });
    }

    /**
     * The guards every line action shares, then the action itself; a refusal
     * becomes the red message on the batch page.
     *
     * @param  callable(): string  $action  returns the success message
     */
    private function lineAction(PayoutBatch $batch, PayoutLineItem $line, callable $action): RedirectResponse
    {
        // Route-model binding resolves the line independently of the batch, so
        // the relationship is checked here or a crafted URL could act on any
        // line item from any batch.
        abort_unless((int) $line->payout_batch_id === (int) $batch->id, 404);

        if (($refusal = $this->lineActionRefusal($batch)) !== null) {
            return back()->with('error', $refusal);
        }

        try {
            $message = $action();
        } catch (PayoutLineActionRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($this->payoutRouteName('show'), $batch)->with('success', $message);
    }

    /** Why no line of this batch may be acted on right now, or null. */
    private function lineActionRefusal(PayoutBatch $batch): ?string
    {
        if ($batch->approved_at === null || ! in_array($batch->status, [
            PayoutBatch::STATUS_APPROVED,
            PayoutBatch::STATUS_DISPATCHED,
            PayoutBatch::STATUS_COMPLETED,
            PayoutBatch::STATUS_PARTIALLY_FAILED,
            PayoutBatch::STATUS_FAILED,
        ], true)) {
            return 'This batch has not been approved, so none of its lines can be settled or sent. Approve the batch first.';
        }

        return $this->projectedFiguresRefusal(
            'The amounts in this batch were computed on a clock that has not arrived, so they cannot be settled or sent to a bank.'
        );
    }

    private function adnOf(PayoutLineItem $line): string
    {
        return (string) ($line->distributor->adn ?? $line->distributor_id);
    }

    /**
     * What the batch page's "Bank files" section and download button need:
     * every file of the batch with its "#n", and how many waiting lines are
     * already in a bank file downloaded since they last became payable.
     *
     * @return array{files: EloquentCollection<int, PayoutBankFile>, ordinals: array<int, int>, import_count: int, pending_already_sent: int}
     */
    protected function bankFilePanel(PayoutBatch $batch): array
    {
        if (! Gate::allows('finance.record')) {
            return ['files' => new EloquentCollection, 'ordinals' => [], 'import_count' => 0, 'pending_already_sent' => 0];
        }

        /** @var EloquentCollection<int, PayoutBankFile> $files */
        $files = $batch->bankFiles()->with('actor')->get();

        /** @var EloquentCollection<int, PayoutLineItem> $pending */
        $pending = $batch->lineItems()
            ->where('status', PayoutLineItem::STATUS_PENDING)
            ->get(['id', 'retry_count']);

        return [
            'files' => $files->reverse()->values(),
            'ordinals' => app(PayoutBankFileDiffService::class)->ordinals($batch),
            'import_count' => $files->where('direction', PayoutBankFile::DIRECTION_IMPORT)->count(),
            'pending_already_sent' => count($this->linesInEarlierBankFiles($pending)),
        ];
    }

    /**
     * Download a stored bank file — the exact bytes that were handed to the
     * bank or uploaded from it. A disclosure of every payee's account number
     * in it, so each download writes an audit row (never the contents).
     */
    public function downloadBankFile(Request $request, PayoutBatch $batch, PayoutBankFile $file, PayoutBankFileVault $vault): StreamedResponse|RedirectResponse
    {
        abort_unless((int) $file->payout_batch_id === (int) $batch->id, 404);

        if ($file->isPurged()) {
            return back()->with('error', 'This file was deleted after the retention period'
                .($file->purged_at !== null ? ' on '.$file->purged_at->format('d M Y') : '')
                .'. Its rows are still shown in the comparisons.');
        }

        $bytes = $vault->read($file);
        if ($bytes === null) {
            return back()->with('error', 'This file could not be found in storage. Tell the developer — the record of it is intact, the stored copy is not.');
        }

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'payout.bank_file.downloaded',
            'subject_type' => 'payout_batch',
            'subject_id' => (int) $batch->id,
            'before_hash' => null,
            'after_hash' => AuditLog::digest($bytes),
            'details' => [
                'payout_bank_file_id' => (int) $file->id,
                'direction' => $file->direction,
                // Deliberately no contents, names or account numbers.
            ],
            'ip' => $request->ip(),
        ]);

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $file->original_name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Compare two bank response files of the batch. Defaults to the latest
     * import against the one before it; `?vs=first` compares it with the first.
     */
    public function compareBankFiles(Request $request, PayoutBatch $batch, PayoutBankFileDiffService $diff): View|RedirectResponse
    {
        $imports = $diff->imports($batch);

        if ($imports->count() < 2) {
            return redirect()->route($this->payoutRouteName('show'), $batch)
                ->with('error', 'Comparing needs at least two imported bank response files for this batch.');
        }

        $ids = $imports->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $latest = end($ids);

        $toId = in_array((int) $request->integer('to'), $ids, true) ? (int) $request->integer('to') : $latest;
        $position = array_search($toId, $ids, true);
        $defaultFrom = $request->query('vs') === 'first' ? $ids[0] : ($ids[max(0, (int) $position - 1)]);
        $fromId = in_array((int) $request->integer('from'), $ids, true) ? (int) $request->integer('from') : $defaultFrom;

        /** @var PayoutBankFile $from */
        $from = $imports->firstWhere('id', $fromId);
        /** @var PayoutBankFile $to */
        $to = $imports->firstWhere('id', $toId);

        return view('admin.compensation.payout-bank-files.compare', [
            'batch' => $batch,
            'imports' => $imports,
            'ordinals' => $diff->ordinals($batch),
            'comparison' => $diff->compare($from, $to),
            'routeBase' => $this->payoutRouteName(''),
        ]);
    }

    /** Every import of the batch side by side, by ADN. */
    public function bankFileHistory(Request $request, PayoutBatch $batch, PayoutBankFileDiffService $diff): View
    {
        $all = $request->boolean('all');

        return view('admin.compensation.payout-bank-files.history', [
            'batch' => $batch,
            'history' => $diff->history($batch, $all),
            'ordinals' => $diff->ordinals($batch),
            'showAll' => $all,
            'routeBase' => $this->payoutRouteName(''),
        ]);
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
    public function exportNeft(Request $request, PayoutBatch $batch, PayoutService $payoutService, PayoutBankFileVault $vault): StreamedResponse|RedirectResponse
    {
        if ($batch->approved_at === null || ! in_array($batch->status, [
            PayoutBatch::STATUS_APPROVED,
            PayoutBatch::STATUS_DISPATCHED,
            PayoutBatch::STATUS_COMPLETED,
            PayoutBatch::STATUS_PARTIALLY_FAILED,
            PayoutBatch::STATUS_FAILED,
        ], true)) {
            return back()->with('error', 'This batch has not been approved yet. Approve it first — the NEFT file is the instruction the bank acts on, and it must not exist before finance has signed the amount off.');
        }

        if (($projected = $this->projectedFiguresRefusal(
            'The amounts in this batch were computed on a clock that has not arrived, so they cannot be approved or sent to a bank.'
        )) !== null) {
            return back()->with('error', $projected);
        }

        // Only the lines still to pay. A paid line in a file that is handed
        // to the bank again is a second payment; a failed one comes back here
        // only once someone has clicked Send again on it.
        /** @var EloquentCollection<int, PayoutLineItem> $lines */
        $lines = $batch->lineItems()
            ->with('distributor.user')
            ->where('status', PayoutLineItem::STATUS_PENDING)
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            return back()->with('error', 'Nothing left to pay in this batch — every line is paid, failed or held.');
        }

        // Built in full before anything is sent: the audit row has to carry a
        // digest of the bytes the admin actually received, which cannot be
        // known while they are still being streamed.
        $csv = $this->buildBankFile($batch, $lines, $payoutService);

        $alreadySent = $this->linesInEarlierBankFiles($lines);

        $filename = 'bank-upload-'.$batch->batch_type.'-'.$batch->batch_date->format('Y-m-d').'-'.now()->format('Hi').'.csv';

        // The file is kept before it is handed over: what the bank was told
        // must be on record. Storage down means no file (fail-closed).
        try {
            $bankFile = $vault->keep($batch, PayoutBankFile::DIRECTION_EXPORT, $csv, $filename, (int) $request->user()->id);
        } catch (Throwable $e) {
            Log::error('Bank file could not be stored — export refused', [
                'payout_batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'The bank file could not be saved to storage, so it was not produced. Every bank file is kept as evidence of what the bank was told — try again, and tell the developer if it keeps failing.');
        }

        try {
            $vault->finish($bankFile, PayoutBankFile::OUTCOME_APPLIED, [
                'line_count' => $lines->count(),
                'total_net_paise' => (int) $lines->sum('net_transferred_paise'),
                'already_in_earlier_file' => count($alreadySent),
            ], array_values(array_map(static fn (PayoutLineItem $line, int $i): array => [
                'row_no' => $i + 1,
                'adn' => (string) ($line->distributor->adn ?? ''),
                'payout_line_item_id' => (int) $line->id,
                'attempt' => (int) $line->retry_count,
                'amount_paise' => (int) $line->net_transferred_paise,
                'result' => PayoutBankFileRow::RESULT_SENT,
            ], $lines->all(), array_keys($lines->all()))));
        } catch (Throwable $e) {
            // Without its `sent` rows the file would not count as "already in
            // a bank file" next time — so no file is handed over at all.
            $vault->discard($bankFile);
            Log::error('Bank file rows could not be recorded — export refused', [
                'payout_batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'The bank file could not be recorded, so it was not produced. Try again, and tell the developer if it keeps failing.');
        }

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
                'scope' => 'unpaid_only',
                'already_in_earlier_file' => count($alreadySent),
                'payout_bank_file_id' => (int) $bankFile->id,
                // Deliberately no account numbers, no names, no file body —
                // the file itself is the encrypted object the id points at.
                'digest_algorithm' => 'sha256',
            ],
            'ip' => $request->ip(),
        ]);

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
     * Which of these waiting lines are already in a bank file downloaded for
     * their current attempt — so the bank may still pay that file.
     *
     * Each exported row records the line's attempt number (`retry_count`). A
     * line that bounced and was sent again has moved to a new attempt, so the
     * file it bounced from no longer counts. The download button warns before
     * a second file for the same attempt is handed over, because uploading
     * both pays those distributors twice.
     *
     * @param  EloquentCollection<int, PayoutLineItem>  $lines
     * @return list<int>
     */
    protected function linesInEarlierBankFiles(EloquentCollection $lines): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        $sent = PayoutBankFileRow::query()
            ->whereIn('payout_line_item_id', $lines->modelKeys())
            ->where('result', PayoutBankFileRow::RESULT_SENT)
            ->get(['payout_line_item_id', 'attempt'])
            ->map(static fn (PayoutBankFileRow $row): string => $row->payout_line_item_id.':'.(int) $row->attempt)
            ->flip();

        $already = [];
        foreach ($lines as $line) {
            if ($sent->has($line->id.':'.(int) $line->retry_count)) {
                $already[] = (int) $line->id;
            }
        }

        return $already;
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
