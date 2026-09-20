<?php

declare(strict_types=1);

/**
 * What the statutory reports claim, against hand-computed figures.
 *
 * The dangerous failures here are the quiet ones: a head decided from the
 * wrong column so CGST is filed where IGST was due, a refund credited against
 * tax it never gave back, a TDS total summed off a column that carries
 * deductions nobody ever made. None of them throw, and a wrong return is
 * signed before anybody notices. So each gets a test with the numbers worked
 * out by hand in the comment.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseInvoiceService;
use App\Modules\Ledger\Services\LedgerPoster;
use App\Modules\Tax\Models\Invoice;
use App\Modules\Tax\Services\InvoiceGenerator;
use App\Modules\Tax\Services\TaxReportService;
use App\Modules\Tax\Support\TaxPeriod;
use Carbon\Carbon;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\XlsxReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** The month every fixture in this file is written into, and read back on. */
const TX_MONTH = '2026-05';

function txMonth(string $month = TX_MONTH): TaxPeriod
{
    return TaxPeriod::fromRequest(Request::create('/admin/reports/profit/gst', 'GET', ['month' => $month]));
}

function txQuarterPeriod(string $quarter): TaxPeriod
{
    return TaxPeriod::fromRequest(Request::create('/admin/reports/profit/gst', 'GET', ['quarter' => $quarter]));
}

function txReports(): TaxReportService
{
    return app(TaxReportService::class);
}

function txVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "TX-{$n}", 'slug' => "tx-{$n}", 'name' => "TX {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "TX-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 118000, 'cost_paise' => 0,
        'landing_price_paise' => 0, 'bv_paise' => 0,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

/**
 * A paid order, GST-inclusive the way CheckoutService writes one, shipping to
 * the given state. `buyerGstin` makes it a B2B supply.
 */
function txPaidOrder(int $inclusivePaise, string $shipState, ?string $buyerGstin = null): Order
{
    $variant = txVariant();
    $gst = (int) round($inclusivePaise * 1800 / 11800);

    $order = Order::create([
        'order_no' => 'ORD-TX-'.random_int(100000, 999999),
        'idempotency_key' => 'tx-idem-'.random_int(1000000, 9999999),
        'customer_id' => Customer::create(['display_name' => 'Buyer '.random_int(1000, 9999)])->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_SHIPPED,
        'self_consumption' => false,
        'subtotal_paise' => $inclusivePaise,
        'gst_paise' => $gst,
        'discount_paise' => 0,
        'shipping_paise' => 0,
        'total_paise' => $inclusivePaise,
        'ship_state' => $shipState,
        'buyer_gstin' => $buyerGstin,
        'buyer_legal_name' => $buyerGstin === null ? null : 'Acme Traders Private Limited',
        'placed_at' => now(),
        'paid_at' => now(),
        'shipped_at' => now(),
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => $variant->name,
        'variant_sku_snapshot' => $variant->variant_sku,
        'hsn_code_snapshot' => '3004',
        'qty' => 1,
        'unit_price_paise' => $inclusivePaise,
        'bv_paise' => 0,
        'gst_rate_bp' => 1800,
        'taxable_value_paise' => $inclusivePaise - $gst,
        'gst_paise' => $gst,
        'line_total_paise' => $inclusivePaise,
    ]);

    return $order;
}

/** The real invoice path, so the head split under test is the one production writes. */
function txInvoiced(int $inclusivePaise, string $shipState, ?string $buyerGstin = null): Order
{
    $order = txPaidOrder($inclusivePaise, $shipState, $buyerGstin);
    app(InvoiceGenerator::class)->generate($order);

    return $order->refresh();
}

/**
 * The accounting entry a refund posts. `$gstPaise` of zero is the buyback case:
 * the tax stays remitted and no `liability.gst_output` debit is written at all.
 */
function txRefund(Order $order, int $taxablePaise, int $gstPaise): void
{
    $lines = [['account' => 'revenue.sales', 'side' => 'debit', 'amount_paise' => $taxablePaise]];

    if ($gstPaise > 0) {
        $lines[] = ['account' => 'liability.gst_output', 'side' => 'debit', 'amount_paise' => $gstPaise];
    }

    $lines[] = ['account' => 'liability.refund_payable', 'side' => 'credit', 'amount_paise' => $taxablePaise + $gstPaise];

    app(LedgerPoster::class)->post(
        sourceModule: 'Returns',
        sourceType: 'order.refund_approved',
        sourceId: (int) $order->id,
        idempotencyKey: 'tx-refund:'.$order->id,
        lines: $lines,
        memo: "Refund approved for {$order->order_no} (reason: cooling_off)",
    );
}

/** A posted goods receipt from a supplier in the given state. */
function txGoodsReceipt(?string $supplierState, int $unitCostPaise, string $invoiceDate): void
{
    $supplier = Supplier::create([
        'name' => 'TX Supplier '.random_int(1000, 9999),
        'gstin' => $supplierState === null ? null : '36AAAAA0000A1Z5',
        'state' => $supplierState,
        'status' => Supplier::STATUS_ACTIVE,
    ]);

    $service = app(PurchaseInvoiceService::class);

    $service->post($service->createDraft(
        [
            'supplier_id' => $supplier->id,
            'purchase_order_id' => null,
            'warehouse_code' => 'DEFAULT',
            'supplier_invoice_no' => 'SI-TX-'.random_int(1000, 9999),
            'supplier_invoice_date' => $invoiceDate,
        ],
        [[
            'product_variant_id' => txVariant()->id,
            'batch_no' => 'TX-'.random_int(1000, 9999),
            'mfg_date' => null, 'expiry_date' => null,
            'qty' => 1, 'unit_cost_paise' => $unitCostPaise, 'gst_rate_bp' => 1800,
        ]],
        User::factory()->create()->id,
    ), User::factory()->create()->id);
}

/** A payout line, and the TDS debit behind it when one was actually made. */
function txPayoutLine(PayoutBatch $batch, string $status, int $tdsPaise, bool $debited): PayoutLineItem
{
    $distributor = Distributor::factory()->create(['pan_last4' => '234F']);

    $line = PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $distributor->id,
        'gross_paise' => 100_000,
        'repurchase_deduction_paise' => 10_000,
        'admin_charge_paise' => 3_000,
        'tds_paise' => $tdsPaise,
        'wallet_balance_paise' => 100_000,
        'net_transferred_paise' => 87_000 - $tdsPaise,
        'status' => $status,
        'retry_count' => 0,
    ]);

    if ($debited) {
        WalletLedgerEntry::create([
            'distributor_id' => $distributor->id,
            'type' => 'tds_debit',
            'amount_paise' => -$tdsPaise,
            'reference_type' => 'payout_line_item',
            'reference_id' => $line->id,
            'memo' => 'TDS (5%)',
        ]);
    }

    return $line;
}

function txFinance(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin-finance');

    return $user;
}

it('splits output tax by head from the place of supply, not from the seller alone', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');

    // Telangana is the supply-from state (tax.seller_state defaults to TG), so
    // this one is intra-state: 1,18,000 inclusive -> 1,00,000 taxable, 18,000
    // tax, split 9,000 CGST + 9,000 SGST.
    txInvoiced(118_000, 'Telangana');
    // Maharashtra is another state: the whole 18,000 is IGST.
    txInvoiced(118_000, 'Maharashtra');

    $data = txReports()->gstSummary(txMonth());

    expect($data['output_by_head']['taxable_paise'])->toBe(200_000)
        ->and($data['output_by_head']['cgst_paise'])->toBe(9_000)
        ->and($data['output_by_head']['sgst_paise'])->toBe(9_000)
        ->and($data['output_by_head']['igst_paise'])->toBe(18_000)
        ->and($data['output_by_head']['invoices'])->toBe(2);

    // By rate must foot to by head, or the two tables on the page disagree.
    $byRate = $data['output_by_rate'];

    expect($byRate)->toHaveCount(1)
        ->and($byRate[0]['rate_bp'])->toBe(1800)
        ->and($byRate[0]['taxable_paise'])->toBe(200_000)
        ->and($byRate[0]['cgst_paise'] + $byRate[0]['sgst_paise'] + $byRate[0]['igst_paise'])
        ->toBe($data['output_by_head']['cgst_paise'] + $data['output_by_head']['sgst_paise'] + $data['output_by_head']['igst_paise']);
});

it('shows a registered buyer on the outward register and never a consumer', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');

    txInvoiced(118_000, 'Telangana');
    txInvoiced(118_000, 'Maharashtra', '27AAAAA0000A1Z5');

    $rows = collect(txReports()->outwardRegister(txMonth(), 100));

    $b2c = $rows->firstWhere('supply_type', 'B2C');
    $b2b = $rows->firstWhere('supply_type', 'B2B');

    expect($b2c['buyer_gstin'])->toBeNull()
        ->and($b2c['buyer_legal_name'])->toBeNull()
        ->and($b2c['pos_code'])->toBe('36')
        ->and($b2b['buyer_gstin'])->toBe('27AAAAA0000A1Z5')
        ->and($b2b['buyer_legal_name'])->toBe('Acme Traders Private Limited')
        ->and($b2b['pos_code'])->toBe('27');
});

it('reads the POS code off a stored state however it was written, and never off a typo', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');

    // Invoices issued before the generator canonicalised the place of supply
    // hold whatever the order carried. `TELANGANA` and `TG` are both the state
    // the code map calls Telangana; `Telengana` is not a state at all, and a
    // register that guessed 36 for it would file a supply against a place
    // nobody said.
    $stored = ['TELANGANA' => '36', 'TG' => '36', 'Telengana' => null];

    foreach ($stored as $written => $expected) {
        $order = txInvoiced(118_000, 'Telangana');
        Invoice::query()->where('order_id', $order->id)->update(['place_of_supply' => $written]);

        $row = collect(txReports()->outwardRegister(txMonth(), 100))
            ->firstWhere('order_no', $order->order_no);

        expect($row['place_of_supply'])->toBe($written)
            ->and($row['pos_code'])->toBe($expected);
    }
});

it('reads a credit note POS code off the same stored state', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Carbon::setTestNow('2026-05-15 10:00:00');

    $order = txInvoiced(118_000, 'Telangana');
    Invoice::query()->where('order_id', $order->id)->update(['place_of_supply' => 'TG']);
    txRefund($order, 100_000, 18_000);

    $row = collect(txReports()->outwardRegister(txMonth(), 100))->firstWhere('doc_type', 'Credit note');

    expect($row['place_of_supply'])->toBe('TG')
        ->and($row['pos_code'])->toBe('36');
});

it('credits a refund only where the ledger actually reversed the tax', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Carbon::setTestNow('2026-05-15 10:00:00');

    $reversed = txInvoiced(118_000, 'Telangana');
    $kept = txInvoiced(118_000, 'Telangana');

    // A cooling-off cancellation returns the tax.
    txRefund($reversed, 100_000, 18_000);
    // A buyback outside the window does not: the tax stays remitted.
    txRefund($kept, 100_000, 0);

    $data = txReports()->gstSummary(txMonth());

    expect($data['credit_notes_by_head']['cgst_paise'])->toBe(9_000)
        ->and($data['credit_notes_by_head']['sgst_paise'])->toBe(9_000)
        ->and($data['credit_notes_by_head']['igst_paise'])->toBe(0)
        ->and($data['credit_notes_by_head']['taxable_paise'])->toBe(100_000)
        ->and($data['refunds_without_tax_credit'])->toBe(1)
        // Two sales, one credit note: 18,000 charged twice, 18,000 given back once.
        ->and($data['net_payable_by_head']['cgst_paise'])->toBe(9_000)
        ->and($data['net_payable_by_head']['sgst_paise'])->toBe(9_000);

    $creditRows = collect(txReports()->outwardRegister(txMonth(), 100))
        ->where('doc_type', 'Credit note');

    expect($creditRows)->toHaveCount(1)
        ->and($creditRows->first()['cgst_paise'])->toBe(-9_000)
        ->and($creditRows->first()['taxable_value_paise'])->toBe(-100_000);
});

it('counts the receipts by what the documents carry, not by the setting as it stands today', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Carbon::setTestNow('2026-05-15 10:00:00');

    // Issued while the registration was still missing: a receipt, and the
    // generator freezes that on the document.
    txInvoiced(118_000, 'Telangana');

    DB::table('settings')->updateOrInsert(
        ['key' => 'tax.seller_gstin'],
        ['value' => '36AABCA1234F1Z5', 'updated_at' => now()],
    );

    // The generator is a singleton and its settings are read once per process;
    // in production the registration is saved in one request and the next
    // invoice is issued in another.
    $this->app->forgetInstance(InvoiceGenerator::class);

    // Issued after the registration landed: a real tax invoice.
    txInvoiced(118_000, 'Telangana');

    $data = txReports()->gstSummary(txMonth());

    // The setting reads as set today, but one of the two documents is still a
    // receipt and its 18,000 of tax is not a GSTR-1 supply.
    expect($data['seller_gstin'])->toBe('36AABCA1234F1Z5')
        ->and($data['receipts_without_gstin'])->toBe(1)
        ->and($data['receipts_without_gstin_gst_paise'])->toBe(18_000);

    $this->actingAs(txFinance())
        ->get(route('admin.reports.profit.gst', ['month' => TX_MONTH]))
        ->assertOk()
        ->assertSee('documents in this period were issued without a seller');
});

it('keeps the credit notes when the invoice lines fill the register cap', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Carbon::setTestNow('2026-05-15 10:00:00');

    // Three invoice lines and one credit note behind a cap of three: capping
    // the lines first and slicing afterwards would drop the credit note.
    txInvoiced(118_000, 'Telangana');
    txInvoiced(118_000, 'Telangana');
    txRefund(txInvoiced(118_000, 'Telangana'), 100_000, 18_000);

    $rows = collect(txReports()->outwardRegister(txMonth(), 3));

    expect($rows)->toHaveCount(3)
        ->and($rows->where('doc_type', 'Credit note'))->toHaveCount(1)
        ->and($rows->where('doc_type', 'Invoice'))->toHaveCount(2);
});

it('derives the inward head from the supplier state and never guesses a missing one', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');

    // 1,00,000 at 18% -> 18,000 GST, split 9,000 / 9,000 intra-state.
    txGoodsReceipt('Telangana', 100_000, '2026-05-10');
    // No state on file: the head cannot be decided, so it is not decided.
    txGoodsReceipt(null, 50_000, '2026-05-11');

    $data = txReports()->gstSummary(txMonth());

    expect($data['input_by_head']['taxable_paise'])->toBe(100_000)
        ->and($data['input_by_head']['cgst_paise'])->toBe(9_000)
        ->and($data['input_by_head']['sgst_paise'])->toBe(9_000)
        ->and($data['input_by_head']['igst_paise'])->toBe(0)
        ->and($data['input_by_head']['unclassified_gst_paise'])->toBe(9_000)
        ->and($data['input_by_head']['unclassified_taxable_paise'])->toBe(50_000)
        ->and($data['input_by_head']['invoices'])->toBe(2)
        // The unclassified input never reduces what is owed.
        ->and($data['net_payable_by_head']['cgst_paise'])->toBe(-9_000);

    $rows = collect(txReports()->inwardRegister(txMonth(), 100));

    expect($rows->pluck('head')->sort()->values()->all())->toBe(['Intra-state', 'Unclassified']);
});

it('dates an inward credit by the supplier invoice, not by when the receipt was posted', function (): void {
    // Posted today, against an invoice dated in the previous quarter.
    Carbon::setTestNow('2026-05-15 10:00:00');
    txGoodsReceipt('Telangana', 100_000, '2026-02-10');

    expect(txReports()->gstSummary(txMonth())['input_by_head']['cgst_paise'])->toBe(0);

    $q4 = txReports()->gstSummary(txQuarterPeriod('2025-26-Q4'));

    expect($q4['input_by_head']['cgst_paise'])->toBe(9_000)
        ->and($q4['input_by_head']['invoices'])->toBe(1);
});

it('counts TDS from the ledger debits, never from the payout line column', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');

    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-05-12',
        'status' => PayoutBatch::STATUS_COMPLETED,
    ]);

    txPayoutLine($batch, PayoutLineItem::STATUS_TRANSFERRED, 500, debited: true);
    // Computed and discarded: no wallet was ever debited.
    txPayoutLine($batch, PayoutLineItem::STATUS_BELOW_MINIMUM, 30, debited: false);
    // Held: rewritten by every batch over the same credits, never deducted.
    txPayoutLine($batch, PayoutLineItem::STATUS_KYC_PENDING, 500, debited: false);

    $data = txReports()->tdsSummary(txMonth());

    expect($data['tds_paise'])->toBe(500)
        ->and($data['deductees'])->toBe(1)
        ->and($data['lines'])->toBe(1)
        // 1,00,000 gross - 10,000 repurchase - 3,000 admin charge.
        ->and($data['payable_paise'])->toBe(87_000)
        ->and($data['below_minimum_lines'])->toBe(1)
        ->and($data['below_minimum_tds_paise'])->toBe(30)
        ->and($data['held_lines'])->toBe(1)
        ->and($data['line_tds_mismatch'])->toBe(0)
        ->and($data['by_batch_type'])->toHaveCount(1)
        ->and($data['by_batch_type'][0]['tds_paise'])->toBe(500);
});

it('masks PAN on the deductee register and never renders a full one', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');

    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-05-12',
        'status' => PayoutBatch::STATUS_COMPLETED,
    ]);

    $line = txPayoutLine($batch, PayoutLineItem::STATUS_TRANSFERRED, 500, debited: true);
    $distributor = Distributor::findOrFail($line->distributor_id);
    $name = User::findOrFail($distributor->user_id)->full_name;

    $rows = txReports()->tdsRegister(txMonth(), 100);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['pan_masked'])->toBe('XXXXXX234F')
        ->and($rows[0]['tds_paise'])->toBe(500)
        ->and($rows[0]['payable_paise'])->toBe(87_000)
        ->and($rows[0]['name'])->toBe($name);

    $body = $this->actingAs(txFinance())
        ->get(route('admin.reports.profit.tds-register', ['month' => TX_MONTH]))
        ->assertOk()
        ->assertSee('XXXXXX234F')
        ->getContent();

    // Nothing shaped like a PAN (5 letters, 4 digits, 1 letter) may appear.
    expect(preg_match('/[A-Z]{5}[0-9]{4}[A-Z]/', (string) $body))->toBe(0);
});

it('records a deductee register read the way it records one leaving the building', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');

    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-05-12',
        'status' => PayoutBatch::STATUS_COMPLETED,
    ]);

    $line = txPayoutLine($batch, PayoutLineItem::STATUS_TRANSFERRED, 500, debited: true);
    $adn = Distributor::findOrFail($line->distributor_id)->adn;

    $finance = txFinance();

    // The summary discloses totals only, so it is not a disclosure to record.
    $this->actingAs($finance)
        ->get(route('admin.reports.profit.tds', ['month' => TX_MONTH]))
        ->assertOk();

    expect(AuditLog::query()->where('action', 'tax.register.viewed')->count())->toBe(0);

    $this->actingAs($finance)
        ->get(route('admin.reports.profit.tds-register', ['month' => TX_MONTH]))
        ->assertOk()
        ->assertSee($adn);

    $rows = AuditLog::query()->where('action', 'tax.register.viewed')->get();

    expect($rows)->toHaveCount(1);

    $details = $rows->first()->details;

    expect($details['period'])->toBe(TX_MONTH)
        ->and($details['rows'])->toBe(1)
        // The record of a disclosure must not become a second copy of it.
        ->and(json_encode($details))->not->toContain($adn);
});

it('lets finance in and keeps compliance out of every statutory view', function (): void {
    $compliance = User::factory()->create();
    $compliance->assignRole('admin-compliance');
    $finance = txFinance();

    foreach (['tds', 'tds-register', 'gst', 'gst-outward', 'gst-inward'] as $view) {
        $this->actingAs($finance)->get(route("admin.reports.profit.{$view}"))->assertOk();
        $this->actingAs($compliance)->get(route("admin.reports.profit.{$view}"))->assertForbidden();
    }
});

it('exports every statutory view as a real workbook, and as CSV', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');
    txInvoiced(118_000, 'Telangana');

    $finance = txFinance();

    foreach (['tds', 'tds-register', 'gst', 'gst-outward', 'gst-inward'] as $view) {
        $xlsx = $this->actingAs($finance)
            ->get(route("admin.reports.profit.{$view}", ['month' => TX_MONTH, 'format' => 'xlsx']))
            ->assertOk()
            ->streamedContent();

        expect(XlsxReader::rows($xlsx))->not->toBeEmpty();

        $this->actingAs($finance)
            ->get(route("admin.reports.profit.{$view}", ['month' => TX_MONTH, 'format' => 'csv']))
            ->assertOk();
    }
});

it('audits a statutory register leaving the building, and exports money ungrouped', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');
    txInvoiced(118_000, 'Telangana');

    $before = AuditLog::query()->where('action', 'tax.report.exported')->count();

    $rows = XlsxReader::rows(
        $this->actingAs(txFinance())
            ->get(route('admin.reports.profit.gst', ['month' => TX_MONTH, 'format' => 'xlsx']))
            ->assertOk()
            ->streamedContent()
    );

    expect(AuditLog::query()->where('action', 'tax.report.exported')->count())->toBe($before + 1)
        // 1,000.00 as a bare number, never "₹1,000.00".
        ->and(XlsxReader::anyCellContains($rows, '1000'))->toBeTrue();
});

it('writes a returned quantity with the minus its money uses, and exports it as a number', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Carbon::setTestNow('2026-05-15 10:00:00');

    txRefund(txInvoiced(118_000, 'Telangana'), 100_000, 18_000);

    $finance = txFinance();

    // The credit-note row reads "−1", the same minus as the "−₹9,000.00"
    // sitting three cells along from it.
    $this->actingAs($finance)
        ->get(route('admin.reports.profit.gst-outward', ['month' => TX_MONTH]))
        ->assertOk()
        ->assertSee('−1', false);

    $rows = XlsxReader::rows(
        $this->actingAs($finance)
            ->get(route('admin.reports.profit.gst-outward', ['month' => TX_MONTH, 'format' => 'xlsx']))
            ->assertOk()
            ->streamedContent()
    );

    // Nothing typographic leaves in the workbook — a spreadsheet cannot sum it.
    expect(XlsxReader::noCellContains($rows, '−'))->toBeTrue()
        ->and(XlsxReader::anyCellContains($rows, '-1'))->toBeTrue();
});

it('titles an export section the way a reader writes one, not in capitals', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');
    txInvoiced(118_000, 'Telangana');

    $finance = txFinance();

    $gst = XlsxReader::rows(
        $this->actingAs($finance)
            ->get(route('admin.reports.profit.gst', ['month' => TX_MONTH, 'format' => 'xlsx']))
            ->assertOk()
            ->streamedContent()
    );

    $tds = XlsxReader::rows(
        $this->actingAs($finance)
            ->get(route('admin.reports.profit.tds', ['month' => TX_MONTH, 'format' => 'xlsx']))
            ->assertOk()
            ->streamedContent()
    );

    expect(XlsxReader::anyCellContains($gst, 'Net outward tax'))->toBeTrue()
        ->and(XlsxReader::anyCellContains($gst, 'Memo'))->toBeTrue()
        ->and(XlsxReader::noCellContains($gst, 'NET OUTWARD TAX'))->toBeTrue()
        ->and(XlsxReader::noCellContains($gst, 'MEMO'))->toBeTrue()
        ->and(XlsxReader::anyCellContains($tds, 'By line status'))->toBeTrue()
        ->and(XlsxReader::noCellContains($tds, 'BY LINE STATUS'))->toBeTrue()
        ->and(XlsxReader::noCellContains($tds, 'MEMO'))->toBeTrue()
        // The acronyms stay capitals — sentence case is not lower case.
        ->and(XlsxReader::anyCellContains($gst, 'CGST payable'))->toBeTrue()
        ->and(XlsxReader::anyCellContains($tds, 'TDS deducted'))->toBeTrue();
});

it('shows the same period on the summary that the register reports', function (): void {
    Carbon::setTestNow('2026-05-15 10:00:00');
    txInvoiced(118_000, 'Telangana');

    expect(Invoice::query()->count())->toBe(1);

    $this->actingAs(txFinance())
        ->get(route('admin.reports.profit.gst', ['month' => TX_MONTH]))
        ->assertOk()
        ->assertSee('May 2026')
        ->assertSee('Output tax collected');

    $this->actingAs(txFinance())
        ->get(route('admin.reports.profit.tds', ['quarter' => '2026-27-Q1']))
        ->assertOk()
        ->assertSee('FY 2026-27 · Q1 (Apr–Jun 2026)', false);

    // Both registers render with real rows in them, not only when empty.
    txGoodsReceipt('Telangana', 100_000, '2026-05-10');

    $this->actingAs(txFinance())
        ->get(route('admin.reports.profit.gst-outward', ['month' => TX_MONTH]))
        ->assertOk()
        ->assertSee('Telangana')
        ->assertSee('B2C');

    $this->actingAs(txFinance())
        ->get(route('admin.reports.profit.gst-inward', ['month' => TX_MONTH]))
        ->assertOk()
        ->assertSee('Intra-state');
});
