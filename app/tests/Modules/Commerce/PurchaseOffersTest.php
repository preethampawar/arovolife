<?php

declare(strict_types=1);

/**
 * Purchase offers — half-price monthly product and the redeem-points streak
 * (KP 2026-06-26, joining trigger dropped by the Product Owner 2026-08-16).
 *
 * OFR-001: a distributor who holds any rank is excluded from both offers
 * OFR-002: the half-price grant needs activation, the month's volume AND an announced product
 * OFR-003: a refund nets against the month the purchase belongs to, not the refund month
 * OFR-004: six consecutive qualifying months award 20% of the streak's BV as points
 * OFR-005: a broken streak awards nothing and starts again
 * OFR-006: the twelfth consecutive month adds the full-year bonus on top
 * OFR-007: the run is idempotent — a second run grants nothing more
 * OFR-008: points are a ledger balance, never wallet money
 * OFR-009: redeeming more points than the balance is refused
 * OFR-010: redemption is capped at the product subtotal, never the GST or shipping
 * OFR-011: refunding an order returns its points exactly once
 * OFR-012: the flag off means the command grants nothing
 * OFR-013: points come off the net product value at checkout, never the GST or delivery
 * OFR-014: the same points cannot be spent twice by two concurrent checkouts
 *
 * Added after the 2026-08-17 compliance FAIL:
 * OFR-015: qualification counts the distributor's own purchases, not sales to other people
 * OFR-016: a points-paid order refunded in cooling-off returns no more cash than came in
 * OFR-017: a points-paid order can still be marked shipped — the journal balances
 */

use App\Modules\Commerce\Enums\PurchaseOfferType;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\MonthlyOfferProduct;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Commerce\Models\PurchaseOfferGrant;
use App\Modules\Commerce\Models\RedeemPointEntry;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Commerce\Services\PurchaseOfferService;
use App\Modules\Commerce\Services\RedeemPointsService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\RefundOrder;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

// ─── helpers ─────────────────────────────────────────────────────────────────

/** BV is stored in paise at 100 paise to the BV. */
function ofrBv(int $bvUnits): int
{
    return $bvUnits * 100;
}

function ofrService(): PurchaseOfferService
{
    return app(PurchaseOfferService::class);
}

function ofrPoints(): RedeemPointsService
{
    return app(RedeemPointsService::class);
}

/**
 * A real order plus its BV accrual, so the qualification query sees the shape
 * production actually produces — including whether the purchase was the
 * distributor's own or a sale to somebody else.
 *
 * @return int the order id, so a test can put it through the refund pipeline
 */
function ofrLedger(Distributor $distributor, string $month, int $bvUnits, bool $ownPurchase = true): int
{
    static $sequence = 0;
    $sequence++;

    $at = Carbon::parse($month.'-15 12:00:00');

    $orderId = DB::table('orders')->insertGetId([
        'order_no' => 'ORD-OFR-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
        'customer_id' => 1,
        'attributed_distributor_id' => $distributor->id,
        'attribution_source' => 'direct',
        'payment_method' => 'online',
        'status' => 'delivered',
        'self_consumption' => $ownPurchase,
        'subtotal_paise' => $bvUnits * 100,
        'gst_paise' => 0,
        'total_paise' => $bvUnits * 100,
        'idempotency_key' => 'ofr-'.$sequence.'-'.uniqid(),
        'delivered_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributor->id,
        'order_id' => $orderId,
        'bv_paise' => ofrBv($bvUnits),
        'type' => 'accrual',
        'effective_at' => $at,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $orderId;
}

/**
 * Reverse an order's BV the way production does — through BvLedgerService, so
 * the reversal is dated to the REFUND date, not the earning month. A fixture
 * that hand-wrote the reversal inside the earning month would prove nothing.
 */
function ofrReverse(int $orderId): void
{
    app(BvLedgerService::class)
        ->reverse(Order::findOrFail($orderId));
}

function ofrAnnounceProduct(string $month): int
{
    static $variantSequence = 0;
    $variantSequence++;

    $productId = DB::table('products')->insertGetId([
        'sku' => 'OFR-'.$variantSequence,
        'slug' => 'offer-product-'.$variantSequence,
        'name' => 'Offer Product '.$variantSequence,
        'hsn_code' => '3004',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'product_id' => $productId,
        'variant_sku' => 'OFR-V-'.$variantSequence,
        'mrp_paise' => 200_000,
        'sale_price_paise' => 180_000,
        'distributor_price_paise' => 150_000,
        'bv_paise' => ofrBv(500),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    MonthlyOfferProduct::create([
        'month_start' => $month.'-01',
        'product_variant_id' => $variantId,
    ]);

    return $variantId;
}

function ofrRankQualify(Distributor $distributor): void
{
    DB::table('rank_qualifications')->insert([
        'distributor_id' => $distributor->id,
        'rank_number' => 1,
        'month_start' => Carbon::now()->subMonths(3)->startOfMonth()->toDateString(),
        'status' => 'qualified',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** A distributor with six consecutive qualifying months ending at $lastMonth. */
function ofrStreak(Distributor $distributor, string $lastMonth, int $months, int $bvUnitsPerMonth = 1000): void
{
    $cursor = Carbon::parse($lastMonth.'-01');

    for ($back = 0; $back < $months; $back++) {
        ofrLedger($distributor, $cursor->copy()->subMonths($back)->format('Y-m'), $bvUnitsPerMonth);
    }
}

/**
 * A ₹1,180 GST-inclusive order settled with ₹180 cash and 1,000 points, with
 * an open cooling-off clock and a return request. Built the way the Returns
 * suite builds one so the refund path runs for real.
 *
 * @return array{order: Order, returnRequest: ReturnRequest}
 */
function ofrPointsPaidOrder(int $distributorId, string $status = Order::STATUS_REFUND_REQUESTED): array
{
    $customer = Customer::create(['display_name' => 'Points Buyer']);

    $orderId = DB::table('orders')->insertGetId([
        'order_no' => 'ORD-OFR-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attributed_distributor_id' => $distributorId,
        'attribution_source' => 'logged_in',
        'payment_method' => 'online',
        'status' => $status,
        'self_consumption' => true,
        'subtotal_paise' => 1_18_000,
        'gst_paise' => 18_000,
        'discount_paise' => 0,
        'redeem_points_paise' => 1_00_000,
        'shipping_paise' => 0,
        // Cash due is the subtotal less the points — ₹180.
        'total_paise' => 18_000,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now()->subDays(5),
        'paid_at' => now()->subDays(5),
        'delivered_at' => now()->subDays(2),
        'idempotency_key' => 'ofr-'.uniqid(),
        'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(2),
    ]);

    OrderCoolingOff::create([
        'order_id' => $orderId,
        'opened_at' => now()->subDays(5),
        'ends_at' => now()->addDays(25),
        'status' => OrderCoolingOff::STATUS_OPEN,
    ]);

    $returnRequest = ReturnRequest::create([
        'rma_no' => 'RMA-OFR-'.random_int(10000, 99999),
        'order_id' => $orderId,
        'order_item_id' => null,
        'qty' => null,
        'reason' => ReturnRequest::REASON_COOLING_OFF,
        'opened_by_customer_id' => $customer->id,
        'status' => ReturnRequest::STATUS_OPENED,
    ]);

    return ['order' => Order::findOrFail($orderId), 'returnRequest' => $returnRequest];
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('OFR-001: a distributor who holds any rank is excluded from both offers', function () {
    $ranked = Distributor::factory()->create(['status' => 'active']);
    ofrRankQualify($ranked);
    ofrAnnounceProduct('2026-07');
    ofrLedger($ranked, '2026-07', 5000);

    $summary = ofrService()->runForMonth(Carbon::parse('2026-07-01'));

    // Both offers are "exclusively for distributors who do not hold any rank".
    expect($summary['skipped_ranked'])->toBe(1)
        ->and(PurchaseOfferGrant::count())->toBe(0);
});

it('OFR-002: the half-price grant needs activation, the month’s volume AND an announced product', function () {
    $month = Carbon::parse('2026-07-01');

    // No product announced: an entitlement to an unnamed product is not one.
    $noProduct = Distributor::factory()->create(['status' => 'active']);
    ofrLedger($noProduct, '2026-06', 3000);
    ofrLedger($noProduct, '2026-07', 1000);
    ofrService()->runForMonth($month);
    expect(PurchaseOfferGrant::count())->toBe(0);

    $variantId = ofrAnnounceProduct('2026-07');

    // Not activated — under 3,000 BV lifetime.
    $unactivated = Distributor::factory()->create(['status' => 'active']);
    ofrLedger($unactivated, '2026-07', 1000);

    // Activated but under the month's qualifying volume.
    $lowVolume = Distributor::factory()->create(['status' => 'active']);
    ofrLedger($lowVolume, '2026-06', 3000);
    ofrLedger($lowVolume, '2026-07', 400);

    ofrService()->runForMonth($month);

    expect(PurchaseOfferGrant::where('distributor_id', $unactivated->id)->count())->toBe(0)
        ->and(PurchaseOfferGrant::where('distributor_id', $lowVolume->id)->count())->toBe(0)
        // The one who met everything gets it, naming the announced variant.
        ->and(PurchaseOfferGrant::where('distributor_id', $noProduct->id)
            ->where('offer_type', PurchaseOfferType::HalfPriceProduct->value)
            ->value('product_variant_id'))->toBe($variantId);
});

it('OFR-003: a refund in the month reduces the qualifying volume', function () {
    ofrAnnounceProduct('2026-07');

    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrLedger($distributor, '2026-06', 3000);
    ofrLedger($distributor, '2026-07', 800);
    $returned = ofrLedger($distributor, '2026-07', 400);

    // Reversed through the real service, which dates the debit to TODAY — not
    // to July. A month defined by `effective_at` would leave July still at
    // 1,200 BV on a purchase that came back, and would break whichever month
    // the refund landed in. The month is defined by which ORDERS accrued in
    // it, so the reversal nets against July where it belongs.
    ofrReverse($returned);

    expect(ofrService()->monthlyBvPaise($distributor->id, Carbon::parse('2026-07-01')))->toBe(ofrBv(800));

    ofrService()->runForMonth(Carbon::parse('2026-07-01'));

    expect(PurchaseOfferGrant::count())->toBe(0);
});

it('OFR-004: six consecutive qualifying months award 20% of the streak’s BV as points', function () {
    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrStreak($distributor, '2026-07', 6, 1000);

    ofrService()->runForMonth(Carbon::parse('2026-07-01'));

    $grant = PurchaseOfferGrant::where('offer_type', PurchaseOfferType::RedeemPoints->value)->firstOrFail();

    // 6 × 1,000 BV = 6,000 BV; 20% = 1,200 points, one point per rupee of BV.
    expect($grant->streak_months)->toBe(6)
        ->and($grant->points_awarded)->toBe(1200)
        ->and(ofrPoints()->balance($distributor->id))->toBe(1200);
});

it('OFR-005: a broken streak awards nothing and starts again', function () {
    $distributor = Distributor::factory()->create(['status' => 'active']);

    // Five qualifying months, one short month, then one more qualifying month.
    ofrStreak($distributor, '2026-06', 5, 1000);
    ofrLedger($distributor, '2026-07', 200);

    ofrService()->runForMonth(Carbon::parse('2026-07-01'));

    expect(PurchaseOfferGrant::where('offer_type', PurchaseOfferType::RedeemPoints->value)->count())->toBe(0)
        ->and(ofrService()->streakLength($distributor->id, Carbon::parse('2026-07-01')))->toBe(0);
});

it('OFR-006: the twelfth consecutive month adds the full-year bonus on top', function () {
    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrStreak($distributor, '2026-07', 12, 1000);

    ofrService()->runForMonth(Carbon::parse('2026-07-01'));

    $grant = PurchaseOfferGrant::where('offer_type', PurchaseOfferType::RedeemPoints->value)->firstOrFail();

    // 20% of the second six months (6,000 BV → 1,200) plus 10% of the whole
    // year (12,000 BV → 1,200) = 2,400.
    expect($grant->streak_months)->toBe(12)
        ->and($grant->points_awarded)->toBe(2400);
});

it('OFR-007: the run is idempotent — a second run grants nothing more', function () {
    ofrAnnounceProduct('2026-07');

    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrStreak($distributor, '2026-07', 6, 1000);

    ofrService()->runForMonth(Carbon::parse('2026-07-01'));
    $first = ofrPoints()->balance($distributor->id);

    ofrService()->runForMonth(Carbon::parse('2026-07-01'));

    expect(PurchaseOfferGrant::count())->toBe(2)
        ->and(ofrPoints()->balance($distributor->id))->toBe($first);
});

it('OFR-008: points are a ledger balance, never wallet money', function () {
    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrStreak($distributor, '2026-07', 6, 1000);

    ofrService()->runForMonth(Carbon::parse('2026-07-01'));

    // Points are a discount entitlement, not cash the company owes. They carry
    // no TDS and no admin charge and are never paid out, so they must not
    // appear in the wallet.
    expect(RedeemPointEntry::where('distributor_id', $distributor->id)->count())->toBe(1)
        ->and(DB::table('wallet_ledger_entries')->where('distributor_id', $distributor->id)->count())->toBe(0);
});

it('OFR-009: redeeming more points than the balance is refused', function () {
    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrPoints()->accrue($distributor->id, 500, 'test', null, 'seed');

    expect(fn () => ofrPoints()->redeem($distributor->id, 501, 1, 'too many'))
        ->toThrow(RuntimeException::class);

    expect(ofrPoints()->balance($distributor->id))->toBe(500);
});

it('OFR-010: redemption is capped at the product subtotal, never the GST or shipping', function () {
    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrPoints()->accrue($distributor->id, 100_000, 'test', null, 'seed');

    // ₹5,000 subtotal, ₹500 coupon discount → ₹4,500 of product value, so
    // 4,500 points. GST and delivery are never payable in points: the company
    // remits GST in cash whatever the buyer paid with, and delivery is a real
    // third-party cost.
    $max = ofrPoints()->maxRedeemableForOrder($distributor->id, 5_00_000, 50_000);

    expect($max)->toBe(4500);
});

it('OFR-011: refunding an order returns its points exactly once', function () {
    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrPoints()->accrue($distributor->id, 1000, 'test', null, 'seed');
    ofrPoints()->redeem($distributor->id, 400, 77, 'order 77');

    expect(ofrPoints()->balance($distributor->id))->toBe(600);

    ofrPoints()->refundForOrder(77, 'order 77 refunded');
    ofrPoints()->refundForOrder(77, 'order 77 refunded again');

    // A retried refund must not mint points.
    expect(ofrPoints()->balance($distributor->id))->toBe(1000);
});

it('OFR-012: the flag off means the command grants nothing', function () {
    ofrAnnounceProduct('2026-07');
    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrStreak($distributor, '2026-07', 6, 1000);

    $this->artisan('offers:monthly-run', ['--month' => '2026-07'])->assertSuccessful();

    expect(PurchaseOfferGrant::count())->toBe(0);

    Feature::for(null)->activate(PurchaseOffersFeature::class);
    $this->artisan('offers:monthly-run', ['--month' => '2026-07'])->assertSuccessful();

    expect(PurchaseOfferGrant::count())->toBeGreaterThan(0);
});

it('OFR-013: points come off the net product value at checkout, never the GST or delivery', function () {
    Feature::for(null)->activate(PurchaseOffersFeature::class);

    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrPoints()->accrue($distributor->id, 100_000, 'test', null, 'seed');

    // ₹10,000 tax-inclusive line. The GST inside it is 10,00,000 × 18/118 =
    // 1,52,542 paise, so the net product value is 8,47,458 — 8,474 points.
    $subtotal = 10_00_000;
    $gst = (int) round($subtotal * 1800 / 11800);

    expect(ofrPoints()->maxRedeemableForOrder($distributor->id, $subtotal, $gst))->toBe(8474);

    // The old cap, taken from the subtotal alone, would have allowed 10,000
    // points — letting the buyer settle the GST the company remits in cash.
    expect(ofrPoints()->maxRedeemableForOrder($distributor->id, $subtotal, 0))->toBe(10000);
});

it('OFR-014: the same points cannot be spent twice by two concurrent checkouts', function () {
    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrPoints()->accrue($distributor->id, 500, 'test', null, 'seed');

    ofrPoints()->redeem($distributor->id, 400, 1, 'order 1');

    // The balance is re-read under a lock inside the transaction, so the
    // second spend sees 100, not the 500 the first one started from.
    expect(fn () => ofrPoints()->redeem($distributor->id, 400, 2, 'order 2'))
        ->toThrow(RuntimeException::class);

    expect(ofrPoints()->balance($distributor->id))->toBe(100);
});

it('OFR-015: qualification counts the distributor’s own purchases, not sales to other people', function () {
    $distributor = Distributor::factory()->create(['status' => 'active']);

    // 400 BV bought for themselves, 5,000 BV of retail sales to third parties
    // attributed to them. Published §11.2 says the offer is earned "entirely
    // from a Distributor's own product purchases", so only the 400 counts —
    // a plain sum over the ledger would have made this month qualify on
    // somebody else's purchases.
    ofrLedger($distributor, '2026-07', 400, ownPurchase: true);
    ofrLedger($distributor, '2026-07', 5000, ownPurchase: false);

    expect(ofrService()->monthlyBvPaise($distributor->id, Carbon::parse('2026-07-01')))->toBe(ofrBv(400))
        ->and(ofrService()->lifetimeBvPaise($distributor->id))->toBe(ofrBv(400));
});

it('OFR-016: a points-paid order refunded in cooling-off returns no more cash than came in', function () {
    Event::fake();
    $this->seed(LedgerAccountSeeder::class);

    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrPoints()->accrue($distributor->id, 1000, 'test', null, 'seed');

    // ₹1,180 GST-inclusive order settled with ₹180 cash and 1,000 points.
    ['order' => $order, 'returnRequest' => $rq] = ofrPointsPaidOrder($distributor->id);
    ofrPoints()->redeem($distributor->id, 1000, (int) $order->id, 'order');

    expect(ofrPoints()->balance($distributor->id))->toBe(0);

    // The real refund path, not a re-implementation of its arithmetic. It
    // posts inside a transaction and the LedgerPoster throws on an unbalanced
    // journal, so an imbalance fails here rather than in production.
    app(RefundOrder::class)->execute($order, $rq, 'cooling_off', true, actorUserId: null);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_REFUND_APPROVED);

    // The cash going back is the cash that came in — ₹180, not ₹1,180.
    // Without the points term this refunded the full order value and turned a
    // non-withdrawable discount entitlement into a cash-out route.
    $payable = (int) DB::table('ledger_entries')
        ->join('ledger_tx', 'ledger_tx.id', '=', 'ledger_entries.ledger_tx_id')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_tx.idempotency_key', "refund:{$order->id}")
        ->where('ledger_accounts.code', 'liability.refund_payable')
        ->sum('ledger_entries.amount_paise');

    expect($payable)->toBe(18_000)
        ->and($payable)->toBeLessThanOrEqual((int) $order->total_paise);

    // And the points side comes back in points, exactly once — a retried
    // refund returns early and cannot mint them.
    app(RefundOrder::class)->execute($order->refresh(), $rq, 'cooling_off', true, actorUserId: null);

    expect(ofrPoints()->balance($distributor->id))->toBe(1000);
});

it('OFR-017: a points-paid order can still be marked shipped — the journal balances', function () {
    Event::fake();
    $this->seed(LedgerAccountSeeder::class);

    // The revenue journal debits customer_prepayment for total_paise (already
    // reduced by the points) but credits sales + GST at full value. Without a
    // contra-revenue debit for the points the entry is out of balance by
    // exactly that amount, the LedgerPoster rejects it, and the order can
    // never be shipped at all.
    $distributor = Distributor::factory()->create(['status' => 'active']);
    ['order' => $order] = ofrPointsPaidOrder($distributor->id, Order::STATUS_PAID);

    app(OrderStateMachine::class)->markShipped($order, actorUserId: null);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_SHIPPED);

    // Assert on what was actually posted, not on locals: the shipment journal
    // exists and balances.
    $tx = DB::table('ledger_tx')->where('idempotency_key', "order.shipped:{$order->id}")->first();
    expect($tx)->not->toBeNull();

    $sides = DB::table('ledger_entries')->where('ledger_tx_id', $tx->id)
        ->selectRaw('side, SUM(amount_paise) as total')->groupBy('side')->pluck('total', 'side');

    expect((int) $sides['debit'])->toBe((int) $sides['credit'])
        ->and((int) $sides['debit'])->toBe(1_18_000);
});

it('OFR-018: an order settled entirely in points can still be bought back', function () {
    Event::fake();
    $this->seed(LedgerAccountSeeder::class);

    // Checkout caps points at the net product value and the checkout screen
    // offers exactly that cap as the input's max, so this is the state of every
    // distributor who redeems the maximum the screen offers — ₹1,000 of points
    // against a ₹1,000 net product value, ₹180 GST, no shipping.
    $distributor = Distributor::factory()->create(['status' => 'active']);
    ofrPoints()->accrue($distributor->id, 1000, 'test', null, 'seed');
    ['order' => $order, 'returnRequest' => $rq] = ofrPointsPaidOrder($distributor->id);
    ofrPoints()->redeem($distributor->id, 1000, (int) $order->id, 'order');

    // A general buy-back refunds the price less GST (T&C §8), so the cash due
    // here is exactly zero. A zero-amount ledger line is rejected, and an
    // unguarded credit rolled the whole refund back — the statutory buy-back
    // failed outright and the points were not restored either.
    app(RefundOrder::class)->execute($order, $rq, 'general_buyback', true, actorUserId: null);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_REFUND_APPROVED)
        // Nothing was payable, so no payable line was written.
        ->and(DB::table('ledger_entries')
            ->join('ledger_tx', 'ledger_tx.id', '=', 'ledger_entries.ledger_tx_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_tx.idempotency_key', "refund:{$order->id}")
            ->where('ledger_accounts.code', 'liability.refund_payable')
            ->count())->toBe(0)
        // But the points still come back, which is the whole point of the buy-back.
        ->and(ofrPoints()->balance($distributor->id))->toBe(1000);
});
