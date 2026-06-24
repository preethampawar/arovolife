<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function makeDistributorWithBv(int $bvPaise): Distributor
{
    $dist = Distributor::factory()->create();
    // Write a BV ledger entry for the distributor so PersonalBvTitleService sees their BV.
    BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => 999_999,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    return $dist;
}

it('returns below_600bv status when personal BV is under 600 BV', function () {
    $dist = makeDistributorWithBv(59_999);  // 599.99 BV
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 2_000_000,
    ]);

    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_BELOW_600BV);
    expect($result->slab)->toBeNull();
    expect($result->net_gsb_paise)->toBe(0);
});

it('returns no_match when group BV does not reach any slab', function () {
    $dist = makeDistributorWithBv(300_000);  // Retailer (3,000 BV)
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 1_000_000,  // 10,000 BV — below 15K threshold
        'right_bv_paise' => 800_000,   // 8,000 BV weaker
    ]);

    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_NO_MATCH);
    expect($result->slab)->toBeNull();
    // Slab1 weaker CF should accumulate the weaker side (800,000 paise)
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->slab1_weaker_bv_paise)->toBe(800_000);
    expect($cf->power_side_bv_paise)->toBe(1_000_000);
    expect($cf->power_side)->toBe('L');
});

it('credits slab 1 when weaker side meets 15,000 BV threshold', function () {
    $dist = makeDistributorWithBv(300_000);  // Retailer
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,   // 20,000 BV
        'right_bv_paise' => 1_600_000,  // 16,000 BV — weaker, ≥ 15K threshold
    ]);

    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($result->slab)->toBe(1);
    expect($result->gross_gsb_paise)->toBe(100_000);   // ₹1,000

    // Admin charge = 3% × 100,000 = 3,000 paise = ₹30
    expect($result->admin_charge_paise)->toBe(3_000);

    // TDS = 5% × (100,000 - 3,000) = 5% × 97,000 = 4,850 paise
    expect($result->tds_paise)->toBe(4_850);
    expect($result->net_gsb_paise)->toBe(92_150);  // 100,000 - 3,000 - 4,850

    // Power CF = stronger (2,000,000) - threshold (1,500,000) = 500,000
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->power_side_bv_paise)->toBe(500_000);
    expect($cf->power_side)->toBe('L');
    expect($cf->slab1_weaker_bv_paise)->toBe(0);  // reset after match
});

it('carries over slab1 weaker CF from previous day to reach threshold', function () {
    $dist = makeDistributorWithBv(300_000);  // Retailer
    // Previous day: weaker was 1,000,000 (10,000 BV) — saved to slab1 CF
    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 1_200_000,   // 12,000 BV power side
        'power_side' => 'R',
        'slab1_weaker_bv_paise' => 1_000_000, // 10,000 BV accumulated
    ]);
    // Today: left = 5,000 BV, right (with 12K CF) = 5K + 12K = 17K
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 500_000,   // 5,000 BV
        'right_bv_paise' => 500_000,  // 5,000 BV
    ]);

    // Effective right = 500K + 1,200K (CF) = 1,700K
    // Effective left  = 500K
    // Weaker = 500K + slab1_CF = 500K + 1,000K = 1,500K >= 1,500K threshold -> slab 1!
    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($result->slab)->toBe(1);
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->slab1_weaker_bv_paise)->toBe(0);  // reset
    // Power CF = stronger_effective(1,700K) - threshold(1,500K) = 200K
    expect($cf->power_side_bv_paise)->toBe(200_000);
    expect($cf->power_side)->toBe('R');
});

it('caps power CF at 45,000,000 paise (450,000 BV)', function () {
    $dist = makeDistributorWithBv(30_000_000);  // Global Distributor
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 800_000_000,  // 8M BV stronger
        'right_bv_paise' => 80_000_000,  // 800K BV weaker — matches slab 5
    ]);

    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($result->slab)->toBe(5);
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    // Without cap: 800M - 80M = 720M paise. Capped at 45M.
    expect($cf->power_side_bv_paise)->toBe(45_000_000);
});

it('marks status as frozen when distributor GSB is frozen', function () {
    $dist = makeDistributorWithBv(1_500_000);  // Wholesaler
    $dist->update(['gsb_frozen_at' => now()]);
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 10_000_000,
        'right_bv_paise' => 10_000_000,
    ]);

    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_FROZEN);
    expect($result->slab)->toBe(3);
    expect($result->gross_gsb_paise)->toBe(600_000);
    expect($result->admin_charge_paise)->toBe(18_000);   // 3% × 600,000
    expect($result->tds_paise)->toBe(29_100);             // 5% × (600,000 − 18,000) = 5% × 582,000
    expect($result->net_gsb_paise)->toBe(552_900);        // 600,000 − 18,000 − 29,100
    // Wallet should NOT have been credited
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(0);
});

it('is idempotent — second call returns existing credited result', function () {
    $dist = makeDistributorWithBv(300_000);
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
    ]);

    $svc = app(GsbCutoffService::class);
    $r1 = $svc->runForDistributor($dist->id, Carbon::today());
    $r2 = $svc->runForDistributor($dist->id, Carbon::today());

    expect($r1->id)->toBe($r2->id);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(1);
});

it('frozen run advances carry-forward so unfreeze does not double-credit', function () {
    // Distributor has enough BV on both sides to match slab 1 (15,000 BV weaker threshold).
    $dist = makeDistributorWithBv(300_000);  // Retailer
    $dist->update(['gsb_frozen_at' => now()]);
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,   // 20,000 BV
        'right_bv_paise' => 1_600_000,  // 16,000 BV weaker — qualifies for slab 1
    ]);

    $svc = app(GsbCutoffService::class);

    // First call: frozen — no wallet credit, but CF must be advanced.
    $frozen = $svc->runForDistributor($dist->id, Carbon::today());
    expect($frozen->status)->toBe(GsbCutoffResult::STATUS_FROZEN);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(0);

    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    // slab1 CF must be reset to 0 so it doesn't re-accumulate while frozen.
    expect($cf->slab1_weaker_bv_paise)->toBe(0);
    // Power CF should be set (stronger - threshold = 2,000,000 - 1,500,000 = 500,000).
    expect($cf->power_side_bv_paise)->toBe(500_000);

    // Unfreeze the distributor.
    $dist->update(['gsb_frozen_at' => null]);

    // Second call on a different date (tomorrow) to avoid the idempotency guard.
    $tomorrow = Carbon::today()->addDay();
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => $tomorrow->toDateString(),
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
    ]);

    $credited = $svc->runForDistributor($dist->id, $tomorrow);
    expect($credited->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    // Exactly one wallet entry — no double-credit.
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(1);
});

it('retries after failure and credits exactly once', function () {
    $dist = makeDistributorWithBv(300_000);  // Retailer — qualifies for slab 1
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
    ]);

    // WalletService is final — substitute via container binding with an
    // anonymous subclass that throws on the first call only.
    $shouldThrow = true;

    app()->bind(WalletService::class, function () use (&$shouldThrow) {
        if ($shouldThrow) {
            return new class extends WalletService
            {
                public function credit(
                    int $distributorId,
                    int $amountPaise,
                    string $type,
                    ?int $referenceId = null,
                    ?string $referenceType = null,
                    ?string $memo = null,
                ): WalletLedgerEntry {
                    throw new RuntimeException('Payment gateway timeout');
                }
            };
        }

        return new WalletService;
    });

    $svc = app(GsbCutoffService::class);

    // First call: wallet throws → STATUS_FAILED.
    $failed = $svc->runForDistributor($dist->id, Carbon::today());
    expect($failed->status)->toBe(GsbCutoffResult::STATUS_FAILED);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(0);

    // Stop throwing so the second call uses the real WalletService.
    $shouldThrow = false;

    // Second call: should succeed and credit exactly once.
    $svc2 = app(GsbCutoffService::class);
    $credited = $svc2->runForDistributor($dist->id, Carbon::today());
    expect($credited->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(1);
});
