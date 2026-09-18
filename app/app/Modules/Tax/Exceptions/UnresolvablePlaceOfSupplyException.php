<?php

declare(strict_types=1);

namespace App\Modules\Tax\Exceptions;

use RuntimeException;

/**
 * The invoice cannot say which head of tax applies.
 *
 * GST does not leave the place of supply open — IGST Act §10(1)(a) determines
 * it — so a state this system cannot read is not a case to guess at. Guessing
 * IGST burns a serial from a deliberately gap-free series onto a row no later
 * render corrects, and hands a B2B buyer an input credit they must reverse
 * with §50 interest.
 *
 * Refusing is the cheaper failure. The sale still stands: generation runs
 * after the payment commits, `PaymentConfirmationService` catches this into a
 * `Log::critical`, `InvoiceGapWorklist` lists the order, and the action centre
 * raises it as a statutory CRITICAL that no manager can silence. CGST §31(1)(b)
 * wants the invoice before delivery, and a short hold to correct a state sits
 * comfortably inside that.
 *
 * **What an operator can actually do about it** differs by which state is
 * unreadable, so the message says rather than offering a correction that does
 * not exist. The seller setting and a collection centre both have admin
 * editors. `orders.ship_state` does not — it is written once at checkout, where
 * it is `required` and picked from a list, so an unreadable one can only be a
 * legacy row. R-28 carries that gap as a dated risk acceptance; a correction
 * screen for it is the remaining follow-up.
 */
final class UnresolvablePlaceOfSupplyException extends RuntimeException
{
    public function __construct(
        public readonly string $orderNo,
        public readonly string $field,
        public readonly ?string $value,
        public readonly string $remedy = 'Correct the state on the record named above, then re-issue the invoice from Admin → Payments.',
    ) {
        parent::__construct(sprintf(
            'Cannot issue an invoice for %s: %s is %s, which is not a state this system recognises. %s',
            $orderNo,
            $field,
            $value === null || trim($value) === '' ? 'empty' : sprintf('"%s"', $value),
            $remedy,
        ));
    }
}
