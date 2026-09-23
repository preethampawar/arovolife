<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services\DTOs;

/**
 * What an offline order will charge, computed from the same price tier,
 * GST split and fulfilment fee the checkout uses — so the amount staff
 * collect is the amount the order will carry.
 */
final readonly class OfflineOrderQuote
{
    /**
     * @param  list<array{variant_id: int, name: string, sku: string, qty: int, unit_price_paise: int, bv_paise: int, line_total_paise: int}>  $lines
     */
    public function __construct(
        public array $lines,
        public int $subtotalPaise,
        public int $gstPaise,
        public int $shippingPaise,
        public int $collectionFeePaise,
        public int $totalPaise,
        public int $bvPaise,
    ) {}
}
