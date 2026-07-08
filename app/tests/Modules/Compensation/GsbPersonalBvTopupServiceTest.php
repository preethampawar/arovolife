<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbPersonalBvTopup;
use App\Modules\Compensation\Services\GsbPersonalBvTopupService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeDistributorForTopupTest(): Distributor
{
    return Distributor::factory()->create();
}

function accrueTopup(int $distributorId, int $bvPaise, ?string $date = null): int
{
    static $orderId = 2_000_000;
    $orderId++;

    BvLedgerEntry::create([
        'distributor_id' => $distributorId,
        'order_id' => $orderId,
        'bv_paise' => $bvPaise,
        'type' => BvLedgerEntry::TYPE_ACCRUAL,
        'effective_at' => Carbon::parse($date ?? today()->toDateString()),
    ]);

    return $orderId;
}

function setGroupBvTopup(int $distributorId, int $left, int $right, ?string $date = null): void
{
    GroupBvDaily::updateOrCreate(
        ['distributor_id' => $distributorId, 'date' => $date ?? today()->toDateString()],
        ['left_bv_paise' => $left, 'right_bv_paise' => $right],
    );
}

// ---------------------------------------------------------------------------
// applyForDistributor
// ---------------------------------------------------------------------------

it('credits personal BV to the weaker (right) side', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 100_000);                   // 1,000 BV
    setGroupBvTopup($dist->id, 500_000, 200_000);      // right is weaker

    app(GsbPersonalBvTopupService::class)->applyForDistributor($dist->id, Carbon::today());

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->right_bv_paise)->toBe(300_000)
        ->and($daily->left_bv_paise)->toBe(500_000);

    expect(GsbPersonalBvTopup::where('distributor_id', $dist->id)->count())->toBe(1);
    expect(GsbPersonalBvTopup::first()->side)->toBe('R');
});

it('credits personal BV to the weaker (left) side when right is stronger', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 50_000);
    setGroupBvTopup($dist->id, 100_000, 400_000);      // left is weaker

    app(GsbPersonalBvTopupService::class)->applyForDistributor($dist->id, Carbon::today());

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->left_bv_paise)->toBe(150_000)
        ->and($daily->right_bv_paise)->toBe(400_000);

    expect(GsbPersonalBvTopup::first()->side)->toBe('L');
});

it('creates group_bv_daily row when none exists for today', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 60_000);
    // No GroupBvDaily row → left treated as weaker (0 <= 0)

    app(GsbPersonalBvTopupService::class)->applyForDistributor($dist->id, Carbon::today());

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily)->not->toBeNull()
        ->and($daily->left_bv_paise)->toBe(60_000)
        ->and($daily->right_bv_paise)->toBe(0);
});

it('is idempotent — running twice does not double-credit', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 100_000);
    setGroupBvTopup($dist->id, 300_000, 100_000);      // right is weaker

    $svc = app(GsbPersonalBvTopupService::class);
    $svc->applyForDistributor($dist->id, Carbon::today());
    $svc->applyForDistributor($dist->id, Carbon::today());

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->right_bv_paise)->toBe(200_000);   // 100k + 100k only once
    expect(GsbPersonalBvTopup::count())->toBe(1);
});

it('applies multiple same-day orders to the same weaker side', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 50_000);
    accrueTopup($dist->id, 30_000);
    setGroupBvTopup($dist->id, 0, 100_000);            // left is weaker

    app(GsbPersonalBvTopupService::class)->applyForDistributor($dist->id, Carbon::today());

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->left_bv_paise)->toBe(80_000)
        ->and($daily->right_bv_paise)->toBe(100_000);

    expect(GsbPersonalBvTopup::count())->toBe(2);
    expect(GsbPersonalBvTopup::pluck('side')->unique()->values()->all())->toBe(['L']);
});

it('ignores accruals from other dates', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 50_000, Carbon::yesterday()->toDateString());
    setGroupBvTopup($dist->id, 0, 0);

    app(GsbPersonalBvTopupService::class)->applyForDistributor($dist->id, Carbon::today());

    expect(GsbPersonalBvTopup::count())->toBe(0);
    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->left_bv_paise)->toBe(0);
});

// ---------------------------------------------------------------------------
// reverseForOrder
// ---------------------------------------------------------------------------

it('reverses an unsettled topup directly from the original date accumulator', function (): void {
    $dist = makeDistributorForTopupTest();
    $orderId = accrueTopup($dist->id, 80_000);
    setGroupBvTopup($dist->id, 0, 200_000);            // left is weaker → topup goes to L

    $svc = app(GsbPersonalBvTopupService::class);
    $svc->applyForDistributor($dist->id, Carbon::today());

    expect(GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->value('left_bv_paise'))
        ->toBe(80_000);

    // No cutoff result → unsettled path.
    $svc->reverseForOrder($orderId);

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->left_bv_paise)->toBe(0);
    expect(GsbPersonalBvTopup::first()->reversed_at)->not->toBeNull();
});

it('deducts from today when the original cutoff date is settled (credited)', function (): void {
    $dist = makeDistributorForTopupTest();
    $yesterday = Carbon::yesterday()->toDateString();
    $today = Carbon::today()->toDateString();

    $orderId = accrueTopup($dist->id, 50_000, $yesterday);
    setGroupBvTopup($dist->id, 0, 300_000, $yesterday);    // left weaker → side='L'

    $svc = app(GsbPersonalBvTopupService::class);
    $svc->applyForDistributor($dist->id, Carbon::yesterday());

    GsbCutoffResult::create([
        'distributor_id' => $dist->id,
        'cutoff_date' => $yesterday,
        'left_bv_paise' => 0,
        'right_bv_paise' => 0,
        'weaker_bv_paise' => 0,
        'gross_gsb_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);

    setGroupBvTopup($dist->id, 120_000, 0, $today);

    $svc->reverseForOrder($orderId);

    // Yesterday's accumulator must be UNCHANGED (settled, no clawback).
    $yest = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', $yesterday)->first();
    expect($yest->left_bv_paise)->toBe(50_000);

    // Today's same-side (L) accumulator is decremented.
    $todayRow = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', $today)->first();
    expect($todayRow->left_bv_paise)->toBe(70_000);   // 120k - 50k
});

it('is idempotent on reversal — calling twice does not double-debit', function (): void {
    $dist = makeDistributorForTopupTest();
    $orderId = accrueTopup($dist->id, 40_000);
    setGroupBvTopup($dist->id, 0, 200_000);

    $svc = app(GsbPersonalBvTopupService::class);
    $svc->applyForDistributor($dist->id, Carbon::today());
    $svc->reverseForOrder($orderId);
    $svc->reverseForOrder($orderId);   // no-op

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->left_bv_paise)->toBe(0);
});
