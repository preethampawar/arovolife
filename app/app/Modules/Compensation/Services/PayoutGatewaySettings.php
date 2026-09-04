<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for how payouts leave the company.
 *
 * Business levers (which gateway is live, the transfer rail, the retry
 * policy, the bank-statement narration) live in the `settings` table and are
 * edited through the platform settings registry, so ops can change them
 * without a deploy. The RazorpayX API credentials never do — they are secrets
 * and are read from config/env only.
 *
 * Deliberately UNCACHED, unlike {@see CompensationPlanSettingsService}. The
 * compensation queue worker is a long-lived process: an in-process cache
 * would let it keep dispatching through a gateway that ops switched off an
 * hour ago, and this service has no flush hook. Every read is one indexed
 * lookup on a five-row set — cheaper than that risk.
 */
final class PayoutGatewaySettings
{
    public const GATEWAY_RAZORPAY = 'razorpay';

    public const GATEWAY_MANUAL_NEFT = 'manual_neft';

    /** @var list<string> */
    public const GATEWAYS = [self::GATEWAY_RAZORPAY, self::GATEWAY_MANUAL_NEFT];

    /** @var list<string> */
    public const TRANSFER_MODES = ['NEFT', 'IMPS', 'RTGS'];

    /**
     * NPCI caps a single IMPS transfer at ₹5,00,000. Anything above it is
     * sent on NEFT instead — see modeFor().
     */
    public const IMPS_MAX_PAISE = 50_000_000;

    public const KEY_GATEWAY = 'payout.gateway';

    public const KEY_MAX_RETRIES = 'payout.razorpay.max_retries';

    public const KEY_AUTO_RETRY_HOURS = 'payout.razorpay.auto_retry_hours';

    public const KEY_TRANSFER_MODE = 'payout.razorpay.transfer_mode';

    public const KEY_NARRATION = 'payout.razorpay.narration';

    /** Registry defaults — used when a key is absent from the settings table. */
    public const DEFAULTS = [
        // Manual NEFT by default: no environment starts moving real money
        // through an API merely because the code shipped.
        self::KEY_GATEWAY => self::GATEWAY_MANUAL_NEFT,
        self::KEY_MAX_RETRIES => '3',
        self::KEY_AUTO_RETRY_HOURS => '24',
        self::KEY_TRANSFER_MODE => 'NEFT',
        self::KEY_NARRATION => 'Arovolife Commission',
    ];

    /**
     * `razorpay` or `manual_neft`. An unrecognised stored value falls back to
     * manual NEFT — a typo in the settings table must never be read as
     * "dispatch real bank transfers".
     */
    public function gateway(): string
    {
        $value = $this->get(self::KEY_GATEWAY);

        return in_array($value, self::GATEWAYS, true) ? $value : self::GATEWAY_MANUAL_NEFT;
    }

    public function isRazorpay(): bool
    {
        return $this->gateway() === self::GATEWAY_RAZORPAY;
    }

    public function isManualNeft(): bool
    {
        return $this->gateway() === self::GATEWAY_MANUAL_NEFT;
    }

    /**
     * Credentials good enough to attempt a call. The debit account number is
     * checked separately: a payout cannot be created without it, but a
     * connection test can.
     */
    public function razorpayConfigured(): bool
    {
        return $this->keyId() !== '' && $this->keySecret() !== '';
    }

    /** Everything a real payout needs, credentials plus the debit account. */
    public function razorpayReady(): bool
    {
        return $this->razorpayConfigured() && $this->accountNumber() !== '';
    }

    public function keyId(): string
    {
        return (string) config('arovolife.payments.razorpay_payouts.key_id', '');
    }

    public function keySecret(): string
    {
        return (string) config('arovolife.payments.razorpay_payouts.key_secret', '');
    }

    public function webhookSecret(): string
    {
        return (string) config('arovolife.payments.razorpay_payouts.webhook_secret', '');
    }

    public function accountNumber(): string
    {
        return (string) config('arovolife.payments.razorpay_payouts.account_number', '');
    }

    public function baseUrl(): string
    {
        return (string) config('arovolife.payments.razorpay_payouts.base_url', 'https://api.razorpay.com/v1');
    }

    public function timeoutSeconds(): int
    {
        return (int) config('arovolife.payments.razorpay_payouts.timeout_seconds', 20);
    }

    /** `test` or `live` from the key prefix, null when the key is absent or malformed. */
    public function razorpayMode(): ?string
    {
        return preg_match('/^rzp_(test|live)_[A-Za-z0-9]{6,}$/', $this->keyId(), $m) === 1 ? $m[1] : null;
    }

    /** Clamped 1–5: an unbounded retry count is an unbounded bill. */
    public function maxRetries(): int
    {
        return max(1, min(5, (int) $this->get(self::KEY_MAX_RETRIES)));
    }

    /** Clamped 1–168 hours (one week). */
    public function autoRetryHours(): int
    {
        return max(1, min(168, (int) $this->get(self::KEY_AUTO_RETRY_HOURS)));
    }

    /** NEFT | IMPS | RTGS — uppercase, as the Razorpay API expects it. */
    public function transferMode(): string
    {
        $value = strtoupper(trim($this->get(self::KEY_TRANSFER_MODE)));

        return in_array($value, self::TRANSFER_MODES, true) ? $value : 'NEFT';
    }

    /**
     * The rail this particular amount can actually travel on.
     *
     * IMPS is capped at ₹5,00,000 per transaction by NPCI; sending more is a
     * gateway rejection, and a rejection on the configured rail would fail
     * exactly the largest payouts. Those fall back to NEFT silently.
     */
    public function modeFor(int $netPaise): string
    {
        $mode = $this->transferMode();

        return $mode === 'IMPS' && $netPaise > self::IMPS_MAX_PAISE ? 'NEFT' : $mode;
    }

    /**
     * What the distributor sees on their bank statement. Razorpay allows up
     * to 30 characters of letters, digits and spaces on the NEFT rails.
     */
    public function narration(): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9 ]/', '', trim($this->get(self::KEY_NARRATION)));
        $value = trim(mb_substr($value, 0, 30));

        return $value !== '' ? $value : 'Arovolife Commission';
    }

    private function get(string $key): string
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        return $value !== null ? (string) $value : (self::DEFAULTS[$key] ?? '');
    }
}
