<?php

declare(strict_types=1);

namespace App\Modules\Tax\Services;

use Illuminate\Support\Facades\DB;

/**
 * The supplier's own tax identity, as it must appear on every invoice.
 *
 * Settings rather than constants because a GSTIN is state-specific and a
 * company that registers in a second state needs to change it without a
 * deploy — and because the placeholder has to be visibly a placeholder until
 * the real registration lands.
 */
final class TaxSettings
{
    private const SCALAR_DEFAULTS = [
        // CGST Rule 46(b). The default is deliberately not a well-formed GSTIN:
        // an invoice carrying it is obviously unissued rather than subtly wrong.
        'tax.seller_gstin' => '',
        'tax.seller_legal_name' => 'Arovolife Private Limited',
        'tax.seller_trade_name' => 'arovolife',
        'tax.seller_state' => 'TG',
        'tax.seller_state_code' => '36',
        'tax.seller_address' => 'H No 6-51/2, Bank Colony, Pothireddipally, Sangareddy B/s Complex, Sangareddy, Medak — 502001, Telangana, India',
        // Rule 46(o) — whether tax is payable on reverse charge. Always no for
        // a normal outward supply of goods; the line is still required.
        'tax.reverse_charge' => false,
    ];

    /** @var array<string, mixed>|null */
    private ?array $scalarCache = null;

    public function sellerGstin(): ?string
    {
        $value = trim($this->scalar('tax.seller_gstin') ?? '');

        return $value === '' ? null : strtoupper($value);
    }

    /**
     * Whether the platform can issue a tax invoice at all.
     *
     * Without a GSTIN the document is a receipt, and it must say so rather than
     * present itself as something it is not.
     */
    public function canIssueTaxInvoice(): bool
    {
        return $this->sellerGstin() !== null;
    }

    public function sellerLegalName(): string
    {
        return $this->scalar('tax.seller_legal_name') ?? (string) self::SCALAR_DEFAULTS['tax.seller_legal_name'];
    }

    public function sellerTradeName(): string
    {
        return $this->scalar('tax.seller_trade_name') ?? (string) self::SCALAR_DEFAULTS['tax.seller_trade_name'];
    }

    public function sellerState(): string
    {
        return strtoupper($this->scalar('tax.seller_state') ?? (string) self::SCALAR_DEFAULTS['tax.seller_state']);
    }

    public function sellerStateCode(): string
    {
        return $this->scalar('tax.seller_state_code') ?? (string) self::SCALAR_DEFAULTS['tax.seller_state_code'];
    }

    public function sellerAddress(): string
    {
        return $this->scalar('tax.seller_address') ?? (string) self::SCALAR_DEFAULTS['tax.seller_address'];
    }

    public function reverseCharge(): bool
    {
        $value = $this->scalar('tax.reverse_charge');

        if ($value === null) {
            return (bool) self::SCALAR_DEFAULTS['tax.reverse_charge'];
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    private function scalar(string $key): ?string
    {
        if ($this->scalarCache === null) {
            $this->scalarCache = DB::table('settings')->pluck('value', 'key')->all();
        }

        $value = $this->scalarCache[$key] ?? null;

        return $value === null ? null : (string) $value;
    }
}
