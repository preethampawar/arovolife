<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use App\Modules\Compensation\Models\GsbReversalRequest;
use RuntimeException;

/**
 * A pending GSB reversal request was approved after the credit it names had
 * changed underneath it.
 *
 * The approver is signing off an amount, not an intention: the request snapshots
 * `net_gsb_paise` and `repurchase_deduction_paise` as the requester saw them, and
 * approval compares that against the live row. If they disagree, reversing would
 * take back a different sum from the one that was authorised — so the approval is
 * refused and the request left pending for someone to look at.
 *
 * A distinct type rather than a bare RuntimeException because the controller
 * catches it by name to show the operator this message: an uncaught throw would
 * render as a blank 500 and tell them nothing.
 *
 * {@see GsbReversalRequest} and R-92 in the risk register.
 */
final class ReversalRequestStale extends RuntimeException
{
    public function __construct(int $reversalRequestId)
    {
        parent::__construct(
            "Reversal request #{$reversalRequestId} no longer matches the credit it was raised against: "
            .'the cut-off row has changed since it was requested, so approving it would reverse a '
            .'different amount from the one put in front of you. Nothing was moved. Reject this '
            .'request and raise a new one against the current figures.'
        );
    }
}
