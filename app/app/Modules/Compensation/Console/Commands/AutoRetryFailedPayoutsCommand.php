<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Jobs\RetryRazorpayPayoutJob;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use Illuminate\Console\Command;

/**
 * Nightly sweep: re-send failed payouts that have gone stale.
 *
 * Most dispatch failures are transient — a gateway blip, a rate limit, a
 * RazorpayX balance that was short at 09:00 and topped up by noon. Retrying
 * them by hand is the kind of task that gets forgotten until a distributor
 * asks where their money is.
 *
 * Two deliberate exclusions:
 *   - `bank_decrypt_failed` and every other held status. Those need a human
 *     to re-capture the details; retrying them just burns the retry budget.
 *   - Any line that already carries a `razorpay_payout_id`. A transfer the
 *     gateway has is finished with by this command, whatever its status.
 */
final class AutoRetryFailedPayoutsCommand extends Command
{
    protected $signature = 'payout:auto-retry-failed
                            {--limit=200 : Maximum line items to queue in one run}';

    protected $description = 'Re-dispatch failed Razorpay payouts that are stale and under the retry limit';

    public function handle(PayoutGatewaySettings $settings): int
    {
        if (! $settings->isRazorpay()) {
            $this->info('Payout gateway is '.$settings->gateway().' — nothing to auto-retry.');

            return self::SUCCESS;
        }

        if (! $settings->razorpayReady()) {
            $this->warn('RazorpayX Payouts is selected but not fully configured — skipping.');

            return self::SUCCESS;
        }

        $staleBefore = now()->subHours($settings->autoRetryHours());
        $limit = max(1, (int) $this->option('limit'));

        $lines = PayoutLineItem::where('status', PayoutLineItem::STATUS_FAILED)
            ->whereNull('razorpay_payout_id')
            ->where('net_transferred_paise', '>', 0)
            ->where('retry_count', '<', $settings->maxRetries())
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('last_retried_at')
                    ->orWhere('last_retried_at', '<', $staleBefore);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($lines as $lineItemId) {
            // actor null = the system retried this, not a person.
            RetryRazorpayPayoutJob::dispatch((int) $lineItemId, null);
        }

        $this->info('Queued '.$lines->count().' failed payout(s) for retry.');

        return self::SUCCESS;
    }
}
