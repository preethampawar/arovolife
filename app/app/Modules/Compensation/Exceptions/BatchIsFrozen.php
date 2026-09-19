<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\PayoutService;
use RuntimeException;

/**
 * A payout batch was asked to be un-built when it may no longer be touched.
 *
 * Two ways a batch reaches that state and both mean the same thing — money, or
 * an instruction to move money, has left the company:
 *
 *  - finance approved it (`approved_at`, or a status past pending). Its remedy
 *    is a per-line retry on the Payouts page, never a recomputation (DN-2).
 *  - it has `payout_gateway_events`, so a request reached Razorpay whatever the
 *    batch row now says.
 *
 * Thrown from inside {@see PayoutService::unbuildBatch()} while the sweep lock
 * is held, so the rebuild that called it aborts before its own transaction
 * opens. A distinct type rather than a bare RuntimeException because the
 * rebuild commands turn it into a refusal an operator can read, and every other
 * failure from that call is a genuine breakage that must not be reported as a
 * decision.
 */
final class BatchIsFrozen extends RuntimeException
{
    public static function approved(PayoutBatch $batch): self
    {
        return new self(sprintf(
            'Batch #%d is %s%s; a batch finance has signed off is not rebuilt — retry its failed lines from the '
            .'Payouts page.',
            $batch->id,
            $batch->status,
            $batch->approved_at !== null ? ' (approved '.$batch->approved_at->format('d M Y H:i').')' : '',
        ));
    }

    public static function reachedGateway(PayoutBatch $batch): self
    {
        return new self(sprintf(
            'Batch #%d has gateway events; it reached Razorpay. Nothing that has been sent to a payment gateway is '
            .'un-built — reconcile it on the Payouts page instead.',
            $batch->id,
        ));
    }
}
