<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console\Commands;

use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Data\ConfirmationResult;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Payments\Services\RazorpayClient;
use App\Modules\Payments\Services\RazorpayRefundService;
use App\Modules\Payments\Support\InvoiceGapWorklist;
use App\Modules\Payments\Support\RefundWorklist;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The backstop behind the callback and the webhook.
 *
 * A buyer closes the tab after paying, the callback never posts, the webhook
 * is delayed or dropped — the money is at the gateway and the order says
 * placed. This asks Razorpay about every open intent older than a few
 * minutes and confirms (or records the failure) from the gateway's own
 * answer, through the same PaymentConfirmationService every other path uses.
 * Nothing here marks an order paid on its own authority.
 *
 * Also settles refund intents the gateway reports processed, and counts
 * paid orders still without an invoice so finance sees them.
 *
 * Idempotent; safe every five minutes. Never cancels — that is
 * orders:expire-unpaid, which calls this first.
 */
final class PaymentsReconcileCommand extends Command
{
    protected $signature = 'payments:reconcile
        {--minutes=3 : Only intents older than this many minutes}
        {--dry-run : Report what would be checked without asking the gateway}';

    protected $description = 'Ask Razorpay about every open payment intent and confirm, fail or leave it from the gateway\'s answer';

    public function handle(PaymentConfirmationService $confirmation, RazorpayClient $client, RazorpayRefundService $refunds, RefundWorklist $worklist, InvoiceGapWorklist $invoiceGaps): int
    {
        if (! $client->configured()) {
            // The hold clocks run whatever the gateway's state: a held refund
            // ages whether or not Razorpay is reachable.
            $clocks = (bool) $this->option('dry-run') ? ['alerted' => 0, 'escalated' => 0] : $worklist->sweep();
            $this->line(sprintf('Razorpay is not configured; nothing to reconcile. Holds: %d alerted, %d escalated.', $clocks['alerted'], $clocks['escalated']));

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subMinutes(max(0, (int) $this->option('minutes')));
        $dryRun = (bool) $this->option('dry-run');

        $tally = ['checked' => 0, 'confirmed' => 0, 'pending' => 0, 'failed' => 0, 'late_capture' => 0, 'errors' => 0];

        PaymentIntent::query()
            ->where('gateway', PaymentIntent::GATEWAY_RAZORPAY)
            ->whereIn('status', [PaymentIntent::STATUS_CREATED, PaymentIntent::STATUS_AUTHORISED])
            // A cancelled order's capture is a late capture, handled when the
            // webhook lands; polling it here would re-alert every run.
            ->whereHas('order', fn ($o) => $o->where('status', Order::STATUS_PLACED))
            ->whereNotNull('gateway_order_id')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($intents) use ($confirmation, $dryRun, &$tally): void {
                foreach ($intents as $intent) {
                    /** @var PaymentIntent $intent */
                    $tally['checked']++;
                    if ($dryRun) {
                        continue;
                    }

                    try {
                        $result = $confirmation->syncAndConfirm($intent, PaymentIntent::CONFIRMED_VIA_RECONCILE);
                        $key = match ($result->status) {
                            ConfirmationResult::CONFIRMED, ConfirmationResult::ALREADY_CONFIRMED => 'confirmed',
                            ConfirmationResult::LATE_CAPTURE => 'late_capture',
                            ConfirmationResult::FAILED => 'failed',
                            default => 'pending',
                        };
                        $tally[$key]++;
                    } catch (Throwable $e) {
                        // A mismatch is already alerted by the service; a
                        // transport failure will be retried next run.
                        $tally['errors']++;
                        Log::channel('payments')->warning('reconcile: intent could not be synced', [
                            'payment_intent_id' => $intent->id,
                            'order_id' => $intent->order_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $refundTally = $dryRun ? ['checked' => 0, 'settled' => 0, 'failed' => 0] : $refunds->reconcileOutstanding();
        $clocks = $dryRun ? ['alerted' => 0, 'escalated' => 0] : $worklist->sweep();

        // A paid order with no invoice is a CGST §31 gap, not a log line:
        // counted here and listed on Admin → Payments for finance to issue.
        $invoiceGapCount = $invoiceGaps->count();
        if ($invoiceGapCount > 0) {
            Log::channel('payments')->warning('paid orders without an invoice', ['count' => $invoiceGapCount]);
        }

        $this->info(sprintf(
            '%s%d intent(s) checked: %d confirmed, %d pending, %d failed, %d late capture, %d error(s). Refunds: %d checked, %d settled, %d failed; holds: %d alerted, %d escalated. Paid orders without invoice: %d.',
            $dryRun ? '[dry run] ' : '',
            $tally['checked'], $tally['confirmed'], $tally['pending'], $tally['failed'], $tally['late_capture'], $tally['errors'],
            $refundTally['checked'], $refundTally['settled'], $refundTally['failed'],
            $clocks['alerted'], $clocks['escalated'], $invoiceGapCount,
        ));

        return self::SUCCESS;
    }
}
