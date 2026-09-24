<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use RuntimeException;

/**
 * A manual payout-line action (mark paid, mark failed, mark returned, send
 * again, check with Razorpay) that the line's current state does not allow.
 *
 * The message is written for the finance user who clicked the button: the
 * controller catches this type by name and shows it verbatim. Nothing has
 * moved when it is thrown — every check runs before the line is written.
 */
final class PayoutLineActionRefused extends RuntimeException {}
