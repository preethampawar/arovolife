<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Support\PaymentSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Which gateway, if any, may take an online order right now.
 *
 *   razorpay flag | credentials                    | result
 *   ON            | valid, mode matches env        | Razorpay
 *   ON            | absent / malformed / wrong mode| CHECKOUT CLOSED + critical alert
 *   OFF           | stub permitted (non-prod, ...) | Stub
 *   OFF           | otherwise                      | no online payment
 *
 * The second row is the one that matters. There is no fallback from
 * Razorpay to the stub: the stub marks orders paid without collecting
 * money, which accrues BV and fires the compensation engines (hard rule 2,
 * R-56). A misconfigured live gateway closes the shop; it never quietly
 * downgrades it to the free one. That was the product owner's call —
 * closing checkout entirely rather than leaving cash-on-delivery open —
 * because a half-working shop is harder to notice than a closed one.
 */
final class PaymentGatewayResolver
{
    public const STATE_RAZORPAY = 'razorpay';

    public const STATE_STUB = 'stub';

    public const STATE_CHECKOUT_CLOSED = 'checkout_closed';

    public const STATE_NONE = 'none';

    private const ALERT_CACHE_KEY = 'payments.razorpay.misconfigured.alerted';

    public function __construct(
        private readonly RazorpayGateway $razorpay,
        private readonly StubGateway $stub,
        private readonly PaymentSettings $settings,
    ) {}

    public function state(): string
    {
        if ($this->settings->razorpayEnabled()) {
            if ($this->razorpay->permitted()) {
                return self::STATE_RAZORPAY;
            }

            $this->alertMisconfigured();

            return self::STATE_CHECKOUT_CLOSED;
        }

        if ($this->settings->stubEnabled() && $this->stub->permitted()) {
            return self::STATE_STUB;
        }

        return self::STATE_NONE;
    }

    /** The gateway to hand an online order to, or null when none may. */
    public function active(): ?PaymentGateway
    {
        return match ($this->state()) {
            self::STATE_RAZORPAY => $this->razorpay,
            self::STATE_STUB => $this->stub,
            default => null,
        };
    }

    public function isRazorpay(): bool
    {
        return $this->state() === self::STATE_RAZORPAY;
    }

    public function onlineAvailable(): bool
    {
        return $this->active() !== null;
    }

    /** Razorpay is switched on but cannot run: nobody may order until it is fixed. */
    public function checkoutClosed(): bool
    {
        return $this->state() === self::STATE_CHECKOUT_CLOSED;
    }

    private function alertMisconfigured(): void
    {
        // Once an hour, not once a request — the checkout page is hit a lot.
        if (! Cache::add(self::ALERT_CACHE_KEY, 1, now()->addHour())) {
            return;
        }

        $context = [
            'environment' => app()->environment(),
            'key_present' => config('arovolife.payments.razorpay.key_id') !== '',
            'key_mode' => app(RazorpayClient::class)->mode(),
            'secret_present' => config('arovolife.payments.razorpay.key_secret') !== '',
            'webhook_secret_present' => config('arovolife.payments.razorpay.webhook_secret') !== '',
        ];

        Log::critical('Razorpay is enabled but cannot run — checkout closed until credentials are fixed', $context);
        Log::channel('payments')->critical('razorpay misconfigured; checkout closed', $context);
    }
}
