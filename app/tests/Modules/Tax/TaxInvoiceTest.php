<?php

declare(strict_types=1);

/**
 * GST tax invoice (R-28, CGST Rule 46).
 *
 * TAX-001: invoice numbers are consecutive and unique within a financial year
 * TAX-002: the financial year runs April to March, not January to December
 * TAX-003: the supplier GSTIN is written onto the invoice
 * TAX-004: with no GSTIN configured the document is a receipt, not a tax invoice
 * TAX-005: an intra-state supply splits CGST and SGST; inter-state is IGST
 * TAX-006: a discount reduces the taxable value, and the invoice foots
 * TAX-007: redeemed points reduce the taxable value the same way
 * TAX-008: the recipient GSTIN is carried onto the invoice
 * TAX-009: generating twice returns the same invoice and burns no number
 */

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Tax\Services\InvoiceGenerator;
use App\Modules\Tax\Services\InvoiceNumberSequence;
use App\Modules\Tax\Services\TaxSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

// ─── helpers ─────────────────────────────────────────────────────────────────

function taxSetGstin(string $gstin = '36AABCA1234F1Z5'): void
{
    DB::table('settings')->updateOrInsert(['key' => 'tax.seller_gstin'], ['value' => $gstin, 'updated_at' => now()]);
}

/**
 * An order with GST-inclusive line prices, built the way CheckoutService does:
 * the tax is extracted out of the gross, never added to it.
 */
function taxOrder(int $grossPaise, string $shipState = 'TG', int $discountPaise = 0, int $redeemPaise = 0, ?string $buyerGstin = null): Order
{
    static $sequence = 0;
    $sequence++;

    $rateBp = 1800;
    $gst = (int) round($grossPaise * $rateBp / (10000 + $rateBp));

    $order = Order::create([
        'order_no' => 'ORD-TAX-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
        'customer_id' => 1,
        'attribution_source' => 'direct',
        'payment_method' => 'online',
        'status' => 'paid',
        'subtotal_paise' => $grossPaise,
        'gst_paise' => $gst,
        'discount_paise' => $discountPaise,
        'redeem_points_paise' => $redeemPaise,
        'shipping_paise' => 0,
        'total_paise' => $grossPaise - $discountPaise - $redeemPaise,
        'idempotency_key' => 'tax-'.$sequence.'-'.uniqid(),
        'ship_state' => $shipState,
        'buyer_gstin' => $buyerGstin,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_variant_id' => 1,
        'product_name_snapshot' => 'Test product',
        'variant_sku_snapshot' => 'SKU-'.$sequence,
        'hsn_code_snapshot' => '3004',
        'qty' => 1,
        'unit_price_paise' => $grossPaise,
        'bv_paise' => 0,
        'gst_rate_bp' => $rateBp,
        'taxable_value_paise' => $grossPaise - $gst,
        'gst_paise' => $gst,
        'line_total_paise' => $grossPaise,
    ]);

    return $order->fresh(['items']);
}

function taxGenerate(Order $order): \App\Modules\Tax\Models\Invoice
{
    return app(InvoiceGenerator::class)->generate($order);
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('TAX-001: invoice numbers are consecutive and unique within a financial year', function () {
    taxSetGstin();

    $numbers = [];

    foreach (range(1, 5) as $ignored) {
        $numbers[] = taxGenerate(taxOrder(10_00_000))->invoice_no;
    }

    // Rule 46(b) wants a consecutive serial unique to the financial year. The
    // previous implementation was `timestamp % 1000000` — not consecutive, and
    // two invoices raised 1,000,000 seconds apart shared one identity.
    expect($numbers)->toHaveCount(5)
        ->and(array_unique($numbers))->toHaveCount(5)
        ->and($numbers[0])->toEndWith('000001')
        ->and($numbers[4])->toEndWith('000005');
});

it('TAX-002: the financial year runs April to March, not January to December', function () {
    $sequence = app(InvoiceNumberSequence::class);

    // India's FY starts in April: 31 March 2027 is still 2026-27.
    expect($sequence->financialYear(Carbon::parse('2026-04-01')))->toBe('2026-27')
        ->and($sequence->financialYear(Carbon::parse('2027-03-31')))->toBe('2026-27')
        ->and($sequence->financialYear(Carbon::parse('2027-04-01')))->toBe('2027-28')
        ->and($sequence->financialYear(Carbon::parse('2026-01-15')))->toBe('2025-26');
});

it('TAX-003: the supplier GSTIN is written onto the invoice', function () {
    taxSetGstin('36AABCA1234F1Z5');

    $invoice = taxGenerate(taxOrder(10_00_000));

    // Without it the document is not a tax invoice and no buyer can claim
    // input credit against it (Rule 46(b)).
    expect($invoice->seller_gstin)->toBe('36AABCA1234F1Z5');
});

it('TAX-004: with no GSTIN configured the document is a receipt, not a tax invoice', function () {
    $tax = app(TaxSettings::class);

    expect($tax->sellerGstin())->toBeNull()
        ->and($tax->canIssueTaxInvoice())->toBeFalse();

    // The invoice row is still written — the split and the numbering are
    // useful either way — but the document must not call itself a tax invoice.
    $invoice = taxGenerate(taxOrder(10_00_000));

    expect($invoice->seller_gstin)->toBeNull();
});

it('TAX-005: an intra-state supply splits CGST and SGST; inter-state is IGST', function () {
    taxSetGstin();

    // Supplier is in Telangana.
    $intra = taxGenerate(taxOrder(10_00_000, 'TG'));
    $inter = taxGenerate(taxOrder(10_00_000, 'KA'));

    expect($intra->igst_paise)->toBe(0)
        ->and($intra->cgst_paise + $intra->sgst_paise)->toBe(1_52_542)
        ->and($intra->cgst_paise)->toBe(76_271)
        ->and($inter->cgst_paise)->toBe(0)
        ->and($inter->sgst_paise)->toBe(0)
        ->and($inter->igst_paise)->toBe(1_52_542);
});

it('TAX-006: a discount reduces the taxable value, and the invoice foots', function () {
    taxSetGstin();

    // ₹10,000 gross less a ₹1,000 coupon → ₹9,000 actually charged. Under CGST
    // §15(3)(a) a discount shown on the invoice comes out of the value, so the
    // tax is 9,00,000 × 18/118 = 1,37,288, not the 1,52,542 on the full price.
    $invoice = taxGenerate(taxOrder(10_00_000, 'TG', 1_00_000));

    $tax = $invoice->cgst_paise + $invoice->sgst_paise + $invoice->igst_paise;

    expect($tax)->toBe(1_37_288)
        // Taxable + tax must equal what the buyer paid. Before this, tax was
        // computed on the undiscounted subtotal and the invoice did not foot.
        ->and($invoice->subtotal_paise + $tax)->toBe(9_00_000);
});

it('TAX-007: redeemed points reduce the taxable value the same way', function () {
    taxSetGstin();

    $invoice = taxGenerate(taxOrder(10_00_000, 'TG', 0, 1_00_000));

    $tax = $invoice->cgst_paise + $invoice->sgst_paise + $invoice->igst_paise;

    expect($invoice->subtotal_paise + $tax)->toBe(9_00_000);
});

it('TAX-008: the recipient GSTIN is carried onto the invoice', function () {
    taxSetGstin();

    $invoice = taxGenerate(taxOrder(10_00_000, 'TG', 0, 0, '29AABCU9603R1ZM'));

    // Rule 46(e): a registered recipient's GSTIN, or they cannot claim credit.
    expect($invoice->buyer_gstin)->toBe('29AABCU9603R1ZM');
});

it('TAX-009: generating twice returns the same invoice and burns no number', function () {
    taxSetGstin();

    $order = taxOrder(10_00_000);

    $first = taxGenerate($order);
    $second = taxGenerate($order);

    expect($second->id)->toBe($first->id)
        ->and($second->invoice_no)->toBe($first->invoice_no);

    // A burnt number would leave a gap the series cannot explain.
    $next = taxGenerate(taxOrder(5_00_000));

    expect($next->invoice_no)->toEndWith('000002');
});
