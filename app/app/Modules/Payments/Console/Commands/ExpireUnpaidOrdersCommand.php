<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console\Commands;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Payments\Data\ConfirmationResult;
use App\Modules\Payments\Exceptions\RazorpayApiException;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Payments\Support\PaymentSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Release online orders nobody paid for.
 *
 * An order is placed (stock reserved, prepayment posted) before the buyer
 * pays, so an abandoned checkout holds stock and overstates gateway cash
 * until something lets go. After the expiry window (settings:
 * payments.unpaid_order_expiry_minutes, ≥ 15) this cancels it — but only
 * after asking the gateway one more time, because "unpaid" on our side can
 * be seconds stale. If Razorpay says captured, the order is confirmed
 * instead; if Razorpay cannot be reached, the order is left alone for the
 * next run rather than cancelled on a guess. cancel() re-reads the order
 * under a row lock, so a webhook landing in the same instant still wins.
 */
final class ExpireUnpaidOrdersCommand extends Command
{
    public const REASON = OrderStateMachine::CANCEL_REASON_PAYMENT_EXPIRED;

    protected $signature = 'orders:expire-unpaid {--dry-run : Report what would be released without changing anything}';

    protected $description = 'Cancel online orders left unpaid past the expiry window, after a final check with the gateway';

    public function handle(PaymentConfirmationService $confirmation, OrderStateMachine $orders, PaymentSettings $settings): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subMinutes($settings->unpaidExpiryMinutes());

        $tally = ['candidates' => 0, 'expired' => 0, 'confirmed_instead' => 0, 'left' => 0];

        Order::query()
            ->where('payment_method', Order::PAYMENT_ONLINE)
            ->where('status', Order::STATUS_PLACED)
            ->whereNull('paid_at')
            ->where('placed_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($batch) use ($confirmation, $orders, $dryRun, &$tally): void {
                foreach ($batch as $order) {
                    /** @var Order $order */
                    $tally['candidates']++;
                    if ($dryRun) {
                        continue;
                    }

                    $tally[$this->expire($order, $confirmation, $orders)]++;
                }
            });

        $this->info(sprintf(
            '%s%d candidate(s): %d expired, %d confirmed instead, %d left for the next run.',
            $dryRun ? '[dry run] ' : '',
            $tally['candidates'], $tally['expired'], $tally['confirmed_instead'], $tally['left'],
        ));

        return self::SUCCESS;
    }

    /** @return 'expired'|'confirmed_instead'|'left' */
    private function expire(Order $order, PaymentConfirmationService $confirmation, OrderStateMachine $orders): string
    {
        $intent = PaymentIntent::where('order_id', $order->id)
            ->where('gateway', PaymentIntent::GATEWAY_RAZORPAY)
            ->orderByDesc('id')
            ->first();

        $reconcile = 'no gateway intent';

        if ($intent !== null && $intent->gateway_order_id !== null && $intent->status !== PaymentIntent::STATUS_CAPTURED) {
            try {
                $result = $confirmation->syncAndConfirm($intent, PaymentIntent::CONFIRMED_VIA_RECONCILE);
                $reconcile = $result->status;
                if ($result->paid()) {
                    return 'confirmed_instead';
                }
                if ($result->status === ConfirmationResult::LATE_CAPTURE) {
                    return 'left';
                }
            } catch (RazorpayApiException $e) {
                // Cannot tell whether it was paid: not today.
                Log::channel('payments')->warning('expire: gateway unreachable, order left placed', [
                    'order_id' => $order->id, 'error' => $e->getMessage(),
                ]);

                return 'left';
            } catch (Throwable $e) {
                // A mismatch is alerted by the service. The order still holds
                // stock; the buyer's money, if any, is handled by the late
                // capture path once the order is cancelled.
                $reconcile = 'error: '.$e->getMessage();
            }
        }

        try {
            // cancel() re-reads under a row lock and refuses this reason on
            // an order paid meanwhile; it also closes the open intent.
            $orders->cancel($order->fresh(), self::REASON, null);
        } catch (Throwable $e) {
            Log::channel('payments')->info('expire: cancel declined', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            return 'left';
        }

        AuditLog::create([
            'actor_id' => null,
            'action' => 'order.expired_by_sweeper',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'before_hash' => hash('sha256', Order::STATUS_PLACED),
            'after_hash' => hash('sha256', Order::STATUS_CANCELLED),
            'details' => [
                'order_no' => $order->order_no,
                'payment_intent_id' => $intent?->id,
                'gateway_order_id' => $intent?->gateway_order_id,
                'final_gateway_check' => $reconcile,
                'placed_at' => $order->placed_at?->toIso8601String(),
            ],
        ]);

        return 'expired';
    }
}
