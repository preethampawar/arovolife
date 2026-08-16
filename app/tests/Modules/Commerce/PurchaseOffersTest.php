<?php

declare(strict_types=1);

/**
 * Purchase offers — half-price monthly product and the redeem-points streak
 * (KP 2026-06-26, joining trigger dropped by the Product Owner 2026-08-16).
 *
 * OFR-001: a distributor who holds any rank is excluded from both offers
 * OFR-002: the half-price grant needs activation, the month's volume AND an announced product
 * OFR-003: a refund in the month reduces the qualifying volume
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
 */

use App\Modules\Commerce\Enums\PurchaseOfferType;
use App\Modules\Commerce\Models\MonthlyOfferProduct;
use App\Modules\Commerce\Models\PurchaseOfferGrant;
use App\Modules\Commerce\Models\RedeemPointEntry;
use App\Modules\Commerce\Services\PurchaseOfferService;
use App\Modules\Commerce\Services\RedeemPointsService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

/** Personal BV in a month. Negative units record a refund. */
function ofrLedger(Distributor $distributor, string $month, int $bvUnits, string $type = 'accrual'): void
{
    static $orderId = 900000;
    $orderId++;

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributor->id,
        'order_id' => $orderId,
        'bv_paise' => ofrBv($bvUnits),
        'type' => $type,
        'effective_at' => Carbon::parse($month.'-15 12:00:00'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
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
    ofrLedger($distributor, '2026-07', 1200);
    ofrLedger($distributor, '2026-07', -400, 'reversal');

    // 1,200 − 400 = 800 BV, under the 1,000 threshold. An offer earned on a
    // purchase that was returned is an offer earned on no sale at all.
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
