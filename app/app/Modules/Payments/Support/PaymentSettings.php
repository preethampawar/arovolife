<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The business levers for payments, read from the `settings` table where the
 * admin console edits them. Credentials never live here — see
 * `config/arovolife.php` `payments.razorpay`.
 */
final class PaymentSettings
{
    public const KEY_RAZORPAY_ENABLED = 'payments.gateway.razorpay.enabled';

    public const KEY_STUB_ENABLED = 'payments.gateway.stub.enabled';

    public const KEY_UNPAID_EXPIRY_MINUTES = 'payments.unpaid_order_expiry_minutes';

    public const KEY_REFUND_SPEED = 'payments.razorpay.refund_speed';

    public const REFUND_SPEED_OPTIMUM = 'optimum';

    public const REFUND_SPEED_NORMAL = 'normal';

    public const DEFAULT_UNPAID_EXPIRY_MINUTES = 30;

    /**
     * Razorpay closes the Checkout modal at this timeout, and the expiry
     * sweeper cancels the order after it. Kept at or above 15 minutes so a
     * UPI-collect request (5–10 minutes) cannot outlive the order it pays.
     */
    public const MIN_UNPAID_EXPIRY_MINUTES = 15;

    public function razorpayEnabled(): bool
    {
        return $this->raw(self::KEY_RAZORPAY_ENABLED) === 'true';
    }

    public function stubEnabled(): bool
    {
        return $this->raw(self::KEY_STUB_ENABLED) === 'true';
    }

    public function unpaidExpiryMinutes(): int
    {
        $value = (int) ($this->raw(self::KEY_UNPAID_EXPIRY_MINUTES) ?? self::DEFAULT_UNPAID_EXPIRY_MINUTES);

        return max(self::MIN_UNPAID_EXPIRY_MINUTES, $value);
    }

    public function refundSpeed(): string
    {
        return $this->raw(self::KEY_REFUND_SPEED) === self::REFUND_SPEED_NORMAL
            ? self::REFUND_SPEED_NORMAL
            : self::REFUND_SPEED_OPTIMUM;
    }

    private function raw(string $key): ?string
    {
        try {
            $value = DB::table('settings')->where('key', $key)->value('value');
        } catch (QueryException) {
            // No settings table yet (a fresh install mid-migration): every
            // lever falls back to its safe default, which for the gateway
            // flags is "off".
            return null;
        }

        return $value === null ? null : (string) $value;
    }
}
