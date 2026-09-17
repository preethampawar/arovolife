<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use RuntimeException;

/**
 * A GSB reversal was approved after the credit it names had already been swept
 * into a payout batch.
 *
 * `reverseBonusCredit()` writes a debit; it does not recall a bank transfer. If
 * the weekly batch has already taken the credit, approving the reversal leaves
 * the wallet short by money that is genuinely gone — an undisclosed clawback
 * netted against whatever the distributor earns next, which the published plan
 * does not provide for. Maker-checker widened the gap between raising a
 * reversal and running it, so this is more reachable than it was, and prose on
 * the confirmation screen is not a control.
 *
 * Refusing is the safe default rather than the complete answer: a credit paid
 * out against a fraudulent or cancelled order still has to be recovered, but
 * that is a finance decision with its own authorisation, not a click on this
 * page. R-92 carries the open question.
 */
final class ReversalCreditAlreadyPaid extends RuntimeException
{
    public static function forBatch(int $reversalRequestId, int $payoutBatchId): self
    {
        return new self(
            "Reversal request #{$reversalRequestId} cannot be approved here: the credit it would take back was "
            ."already swept into payout batch #{$payoutBatchId} and may have reached the distributor's bank. "
            .'A wallet debit does not recall a transfer, so approving it would leave the wallet short by money '
            .'that is gone and net the difference against future earnings. Nothing was moved. Recovering a paid '
            .'credit is a finance decision with its own authorisation — reject this request and raise it there.'
        );
    }
}
