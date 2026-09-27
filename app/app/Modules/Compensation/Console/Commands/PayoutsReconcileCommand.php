<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Exceptions\PayoutLineActionRefused;
use App\Modules\Compensation\Jobs\DispatchRazorpayPayoutLineJob;
use App\Modules\Compensation\Models\PayoutBankFileRow;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\PayoutLineSettlementService;
use App\Modules\Compliance\Models\AuditLog;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backstop for transfers the webhook never settled, and for lines a killed
 * job never sent. The webhook stays the primary confirmation.
 *
 *   A. Waiting on the bank too long — `pending` with a payout id, sent more
 *      than --hours ago. Razorpay is asked where each stands, exactly as the
 *      per-line "Check with Razorpay" button does.
 *   B. Never sent — `pending` with no payout id in a `dispatched` batch
 *      approved more than --hours ago, and not in an NEFT bank file for its
 *      current attempt (the bank may have paid it; Razorpay cannot see that). Re-queued; the dispatch service asks
 *      Razorpay for the line's reference before sending, and its fresh-read
 *      guard makes a duplicate queue entry harmless.
 *
 * A no-op in Manual NEFT mode.
 */
final class PayoutsReconcileCommand extends Command
{
    protected $signature = 'payouts:reconcile
                            {--hours=6 : Only lines dispatched or queued longer ago than this}
                            {--limit=500 : Maximum line items per selection}
                            {--dry-run : Report what would be checked and re-queued, change nothing}';

    protected $description = 'Ask Razorpay about payouts still in flight and re-queue lines a killed job never sent';

    public function handle(PayoutGatewaySettings $settings, PayoutLineSettlementService $settlement): int
    {
        if (! $settings->isRazorpay()) {
            $this->info('Payout gateway is '.$settings->gateway().' — nothing to reconcile.');

            return self::SUCCESS;
        }

        if (! $settings->razorpayReady()) {
            $this->warn('RazorpayX Payouts is selected but not fully configured — skipping.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours(max(1, (int) $this->option('hours')));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $inFlight = self::awaitingBank($cutoff)->orderBy('id')->limit($limit)->get();
        $unsent = self::unsentInDispatchedBatch($cutoff)->orderBy('payout_line_items.id')->limit($limit)
            ->pluck('payout_line_items.id');

        if ($dryRun) {
            $this->info(sprintf(
                'Dry run: would check %d with Razorpay and re-queue %d unsent line(s).',
                $inFlight->count(),
                $unsent->count(),
            ));

            return self::SUCCESS;
        }

        $tally = ['transferred' => 0, 'failed' => 0, 'in_flight' => 0, 'unreachable' => 0];

        foreach ($inFlight as $line) {
            try {
                $outcome = $settlement->checkWithRazorpay($line, null);
            } catch (PayoutLineActionRefused $e) {
                $this->warn("Line {$line->id}: {$e->getMessage()}");
                $tally['unreachable']++;

                continue;
            }

            match ($outcome) {
                PayoutLineItem::STATUS_TRANSFERRED => $tally['transferred']++,
                PayoutLineItem::STATUS_FAILED => $tally['failed']++,
                default => $tally['in_flight']++,
            };
        }

        foreach ($unsent as $lineItemId) {
            // actor null = the system re-queued this, not a person.
            DispatchRazorpayPayoutLineJob::dispatch((int) $lineItemId, null);
        }

        if ($unsent->isNotEmpty()) {
            // Re-queueing is an instruction to send money: on the record,
            // before any line job acts on it.
            AuditLog::create([
                'actor_id' => null,
                'action' => 'payout.reconcile.requeued',
                'subject_type' => 'payout_line_item',
                'subject_id' => (int) $unsent->first(),
                'details' => [
                    'payout_line_item_ids' => $unsent->map(static fn ($id): int => (int) $id)->values()->all(),
                    'hours' => (int) $this->option('hours'),
                ],
                'ip' => null,
            ]);
        }

        $this->info(sprintf(
            'Checked %d with Razorpay (%d transferred, %d failed, %d still in flight, %d unreachable); re-queued %d unsent line(s).',
            $inFlight->count(),
            $tally['transferred'],
            $tally['failed'],
            $tally['in_flight'],
            $tally['unreachable'],
            $unsent->count(),
        ));

        // Every check failing means Razorpay itself is unreachable: exit
        // non-zero so it shows in the scheduler log.
        return $inFlight->isNotEmpty() && $tally['unreachable'] === $inFlight->count()
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * With Razorpay, no confirmation yet, sent before the cutoff. Shared with
     * the Action Center item so the two can never disagree.
     *
     * @return Builder<PayoutLineItem>
     */
    public static function awaitingBank(DateTimeInterface $sentBefore): Builder
    {
        return PayoutLineItem::query()
            ->where('payout_line_items.status', PayoutLineItem::STATUS_PENDING)
            ->whereNotNull('payout_line_items.razorpay_payout_id')
            ->where('payout_line_items.razorpay_payout_id', '!=', '')
            ->where('payout_line_items.dispatched_at', '<', $sentBefore);
    }

    /**
     * Payable, never sent, in a batch handed to Razorpay before the cutoff.
     * Shared with the Action Center item, which keeps the bank-filed lines:
     * they must not be sent from here, but a person must still see them.
     *
     * @return Builder<PayoutLineItem>
     */
    public static function unsentInDispatchedBatch(DateTimeInterface $approvedBefore, bool $includeBankFiled = false): Builder
    {
        $query = PayoutLineItem::query()
            ->join('payout_batches', 'payout_batches.id', '=', 'payout_line_items.payout_batch_id')
            ->where('payout_line_items.status', PayoutLineItem::STATUS_PENDING)
            ->whereNull('payout_line_items.razorpay_payout_id')
            ->where('payout_line_items.net_transferred_paise', '>', 0)
            ->where('payout_batches.status', PayoutBatch::STATUS_DISPATCHED)
            ->where('payout_batches.approved_at', '<', $approvedBefore)
            ->select('payout_line_items.*');

        if ($includeBankFiled) {
            return $query;
        }

        return $query
            // A line handed to the bank in an NEFT file for this attempt may
            // already be paid; Razorpay cannot see that, so it is never
            // re-queued. The bank's answer is recorded on it instead.
            ->whereNotExists(function ($row): void {
                $row->selectRaw('1')
                    ->from('payout_bank_file_rows')
                    ->whereColumn('payout_bank_file_rows.payout_line_item_id', 'payout_line_items.id')
                    ->whereColumn('payout_bank_file_rows.attempt', 'payout_line_items.retry_count')
                    ->where('payout_bank_file_rows.result', PayoutBankFileRow::RESULT_SENT);
            });
    }
}
