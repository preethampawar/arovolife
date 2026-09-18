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
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Tax\Exceptions\UnresolvablePlaceOfSupplyException;
use App\Modules\Tax\Models\Invoice;
use App\Modules\Tax\Services\InvoiceGenerator;
use App\Modules\Tax\Services\InvoiceNumberSequence;
use App\Modules\Tax\Services\TaxSettings;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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
function taxOrder(int $grossPaise, string $shipState = 'Telangana', int $discountPaise = 0, int $redeemPaise = 0, ?string $buyerGstin = null): Order
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

function taxGenerate(Order $order): Invoice
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
    $intra = taxGenerate(taxOrder(10_00_000, 'Telangana'));
    $inter = taxGenerate(taxOrder(10_00_000, 'Karnataka'));

    expect($intra->igst_paise)->toBe(0)
        ->and($intra->cgst_paise + $intra->sgst_paise)->toBe(1_52_542)
        ->and($intra->cgst_paise)->toBe(76_271)
        ->and($inter->cgst_paise)->toBe(0)
        ->and($inter->sgst_paise)->toBe(0)
        ->and($inter->igst_paise)->toBe(1_52_542);
});

it('TAX-006: the invoice declares the tax that was actually charged and remitted', function () {
    taxSetGstin();

    // ₹10,000 gross less a ₹1,000 coupon → ₹9,000 collected. The company still
    // computes GST on the full ₹10,000 at checkout and credits
    // liability.gst_output with that figure when the order ships, so the
    // invoice — the source for GSTR-1 — must declare the same 1,52,542. An
    // invoice that claimed the §15(3)(a) reduction here would put the return
    // and the books ₹15,254 apart on this one order.
    $invoice = taxGenerate(taxOrder(10_00_000, 'TG', 1_00_000));

    $tax = $invoice->cgst_paise + $invoice->sgst_paise + $invoice->igst_paise;

    expect($tax)->toBe(1_52_542)
        ->and($invoice->subtotal_paise + $tax)->toBe(10_00_000);
});

it('TAX-007: redeemed points do not reduce the declared tax either', function () {
    taxSetGstin();

    // Same treatment for points, which matters more: the published copy tells
    // distributors that points "cannot be used to pay GST". If redeeming them
    // reduced the tax charged, that sentence would be false and the ledger
    // would still be carrying the full amount.
    $invoice = taxGenerate(taxOrder(10_00_000, 'TG', 0, 1_00_000));

    $tax = $invoice->cgst_paise + $invoice->sgst_paise + $invoice->igst_paise;

    expect($tax)->toBe(1_52_542)
        ->and($invoice->subtotal_paise + $tax)->toBe(10_00_000);
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

it('TAX-010: the invoice, the order and the shipment journal all carry the same GST', function () {
    taxSetGstin();
    $this->seed(LedgerAccountSeeder::class);
    Event::fake();

    // A ₹10,000 order settled with a ₹1,000 coupon AND 1,000 points — both
    // reductions at once, which is where the three figures previously drifted
    // apart. The invoice is the source for GSTR-1 and the journal is the
    // source for the books; if they disagree, one of them is wrong and nobody
    // finds out until a return is filed.
    $order = taxOrder(10_00_000, 'TG', 1_00_000, 1_00_000);

    $invoice = taxGenerate($order);
    $invoiceTax = $invoice->cgst_paise + $invoice->sgst_paise + $invoice->igst_paise;

    app(OrderStateMachine::class)->markShipped($order);

    $posted = (int) DB::table('ledger_entries')
        ->join('ledger_tx', 'ledger_tx.id', '=', 'ledger_entries.ledger_tx_id')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_tx.idempotency_key', "order.shipped:{$order->id}")
        ->where('ledger_accounts.code', 'liability.gst_output')
        ->sum('ledger_entries.amount_paise');

    expect($invoiceTax)->toBe((int) $order->gst_paise)
        ->and($posted)->toBe((int) $order->gst_paise);
});

it('TAX-010: the seller state code and the order state name are the same state', function () {
    taxSetGstin();

    // `tax.seller_state` persists 'TG' (the setting caps at two characters);
    // checkout persists 'Telangana'. Compared raw they are never equal, so
    // every supply the company made from its own state was billed IGST and
    // remitted to the wrong head. Both sides normalise before the comparison.
    $invoice = taxGenerate(taxOrder(10_00_000, 'Telangana'));

    expect($invoice->igst_paise)->toBe(0)
        ->and($invoice->cgst_paise + $invoice->sgst_paise)->toBe(1_52_542)
        ->and($invoice->seller_state)->toBe('Telangana')
        ->and($invoice->place_of_supply)->toBe('Telangana');
});

it('TAX-011: an unreadable place of supply is refused, not guessed', function () {
    taxSetGstin();

    // There is no "unknown" head of tax under IGST s10(1)(a). Guessing IGST
    // burns a serial from a gap-free series onto a row nothing re-renders and
    // hands a B2B buyer a credit they must reverse with interest. Refusing
    // leaves the sale standing and raises the invoice-gap worklist instead.
    $order = taxOrder(10_00_000, 'Atlantis');

    expect(fn () => taxGenerate($order))
        ->toThrow(UnresolvablePlaceOfSupplyException::class);

    // And it wrote nothing on the way out: no invoice, and no number burned.
    expect(Invoice::where('order_id', $order->id)->exists())->toBeFalse()
        ->and(DB::table('invoice_number_sequences')->sum('last_number'))->toBe(0);
});

it('TAX-013: an unreadable seller state is refused too', function () {
    taxSetGstin();
    DB::table('settings')->updateOrInsert(['key' => 'tax.seller_state'], ['value' => 'XX', 'updated_at' => now()]);

    // Misconfiguring the supply-from state cannot be allowed to bill every
    // order as inter-state; it is a configuration error, not a tax position.
    expect(fn () => taxGenerate(taxOrder(10_00_000, 'Telangana')))
        ->toThrow(UnresolvablePlaceOfSupplyException::class);
});

it('TAX-012: an absent place of supply is refused, not read as the seller state', function () {
    taxSetGstin();

    // This used to fall back to the seller's own state and bill CGST+SGST. It
    // read as "the collection case" and it was not: a collection takes the
    // CENTRE's state, so the only way this is empty is a record with no state
    // at all — and guessing intra-state there charges the wrong head on a
    // supply that may well be inter-state, onto a serial nothing re-renders.
    expect(fn () => taxGenerate(taxOrder(10_00_000, '')))
        ->toThrow(UnresolvablePlaceOfSupplyException::class);

    // And it burns no number doing it (Rule 46(b) wants the series gap-free).
    expect((int) DB::table('invoice_number_sequences')->sum('last_number'))->toBe(0)
        ->and(Invoice::count())->toBe(0);
});

it('TAX-014: a collection at a centre with no state is refused and names the centre', function () {
    taxSetGstin();

    $centerId = DB::table('arete_centers')->insertGetId([
        'name' => 'Stateless Centre',
        'centre_type' => 'company',
        'status' => 'active',
        'state' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $order = taxOrder(10_00_000, '');
    $order->update(['delivery_type' => Order::DELIVERY_COLLECT, 'arete_center_id' => $centerId]);

    // The operator has to be told WHICH record to fix. A centre does have an
    // admin editor, so this message names an action that exists.
    expect(fn () => taxGenerate($order->fresh(['items'])))
        ->toThrow(UnresolvablePlaceOfSupplyException::class, 'Stateless Centre');
});
