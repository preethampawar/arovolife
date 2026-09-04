<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * The gateway's payment does not match the order it claims to pay: wrong
 * gateway order, wrong amount, wrong currency, partly refunded, or an order
 * that is no longer waiting for payment. Never confirmed; always alerted.
 */
final class PaymentMismatchException extends RuntimeException {}
