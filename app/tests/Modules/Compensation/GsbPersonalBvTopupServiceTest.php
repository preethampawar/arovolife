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
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    // Permissive go-live so cross-day pending accruals are in-window. The
    // conditional weaker side is now decided by the caller (GsbCutoffService);
    // these tests exercise the apply/window/reversal mechanics with an explicit
    // side, and cover the go-live boundary and cross-day accumulation directly.
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.gsb.topup_golive_date'],
        ['value' => '2000-01-01'],
    );
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
// applyPendingForDistributor
// ---------------------------------------------------------------------------

it('credits pending personal BV to the caller-chosen (right) weaker side', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 100_000);                   // 1,000 BV
    setGroupBvTopup($dist->id, 500_000, 200_000);

    $credited = app(GsbPersonalBvTopupService::class)
        ->applyPendingForDistributor($dist->id, Carbon::today(), 'R');

    expect($credited)->toBe(100_000);

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->right_bv_paise)->toBe(300_000)
        ->and($daily->left_bv_paise)->toBe(500_000);

    expect(GsbPersonalBvTopup::where('distributor_id', $dist->id)->count())->toBe(1);
    expect(GsbPersonalBvTopup::first()->side)->toBe('R');
});

it('credits pending personal BV to the caller-chosen (left) weaker side', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 50_000);
    setGroupBvTopup($dist->id, 100_000, 400_000);

    app(GsbPersonalBvTopupService::class)->applyPendingForDistributor($dist->id, Carbon::today(), 'L');

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->left_bv_paise)->toBe(150_000)
        ->and($daily->right_bv_paise)->toBe(400_000);

    expect(GsbPersonalBvTopup::first()->side)->toBe('L');
});

it('creates group_bv_daily row when none exists for the cutoff date', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 60_000);

    app(GsbPersonalBvTopupService::class)->applyPendingForDistributor($dist->id, Carbon::today(), 'L');

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily)->not->toBeNull()
        ->and($daily->left_bv_paise)->toBe(60_000)
        ->and($daily->right_bv_paise)->toBe(0);
});

it('is idempotent — running twice does not double-credit', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 100_000);
    setGroupBvTopup($dist->id, 300_000, 100_000);

    $svc = app(GsbPersonalBvTopupService::class);
    expect($svc->applyPendingForDistributor($dist->id, Carbon::today(), 'R'))->toBe(100_000);
    expect($svc->applyPendingForDistributor($dist->id, Carbon::today(), 'R'))->toBe(0);

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->right_bv_paise)->toBe(200_000);   // 100k + 100k only once
    expect(GsbPersonalBvTopup::count())->toBe(1);
});

it('applies multiple pending orders to the same weaker side', function (): void {
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 50_000);
    accrueTopup($dist->id, 30_000);
    setGroupBvTopup($dist->id, 0, 100_000);

    $credited = app(GsbPersonalBvTopupService::class)
        ->applyPendingForDistributor($dist->id, Carbon::today(), 'L');

    expect($credited)->toBe(80_000);

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->left_bv_paise)->toBe(80_000)
        ->and($daily->right_bv_paise)->toBe(100_000);

    expect(GsbPersonalBvTopup::count())->toBe(2);
    expect(GsbPersonalBvTopup::pluck('side')->unique()->values()->all())->toBe(['L']);
});

it('accumulates uncredited accruals from prior days into the pending pool', function (): void {
    // New rule (KP 2026-07-21): personal BV is NOT day-scoped — an accrual left
    // uncredited yesterday is still pending today and credited on the triggering day.
    $dist = makeDistributorForTopupTest();
    accrueTopup($dist->id, 40_000, Carbon::yesterday()->toDateString());
    accrueTopup($dist->id, 60_000, today()->toDateString());

    $credited = app(GsbPersonalBvTopupService::class)
        ->applyPendingForDistributor($dist->id, Carbon::today(), 'L');

    expect($credited)->toBe(100_000);   // both days' BV
    expect(GsbPersonalBvTopup::count())->toBe(2);
});

it('excludes accruals dated before the go-live date', function (): void {
    $dist = makeDistributorForTopupTest();
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.gsb.topup_golive_date'],
        ['value' => today()->toDateString()],
    );
    accrueTopup($dist->id, 50_000, today()->subDay()->toDateString());   // pre-go-live

    $credited = app(GsbPersonalBvTopupService::class)
        ->applyPendingForDistributor($dist->id, Carbon::today(), 'L');

    expect($credited)->toBe(0);
    expect(GsbPersonalBvTopup::count())->toBe(0);
});

it('excludes an order that has been reversed', function (): void {
    $dist = makeDistributorForTopupTest();
    $orderId = accrueTopup($dist->id, 70_000);
    // Cancellation writes a reversal ledger entry for the same order.
    BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => $orderId,
        'bv_paise' => -70_000,
        'type' => BvLedgerEntry::TYPE_REVERSAL,
        'effective_at' => now(),
    ]);

    $credited = app(GsbPersonalBvTopupService::class)
        ->applyPendingForDistributor($dist->id, Carbon::today(), 'L');

    expect($credited)->toBe(0);
    expect(GsbPersonalBvTopup::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// reverseForOrder
// ---------------------------------------------------------------------------

it('reverses an unsettled topup directly from the original date accumulator', function (): void {
    $dist = makeDistributorForTopupTest();
    $orderId = accrueTopup($dist->id, 80_000);
    setGroupBvTopup($dist->id, 0, 200_000);

    $svc = app(GsbPersonalBvTopupService::class);
    $svc->applyPendingForDistributor($dist->id, Carbon::today(), 'L');

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
    setGroupBvTopup($dist->id, 0, 300_000, $yesterday);

    $svc = app(GsbPersonalBvTopupService::class);
    $svc->applyPendingForDistributor($dist->id, Carbon::yesterday(), 'L');

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
    $svc->applyPendingForDistributor($dist->id, Carbon::today(), 'L');
    $svc->reverseForOrder($orderId);
    $svc->reverseForOrder($orderId);   // no-op

    $daily = GroupBvDaily::where('distributor_id', $dist->id)->whereDate('date', today())->first();
    expect($daily->left_bv_paise)->toBe(0);
});
