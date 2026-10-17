<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\RankRequalificationGateService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/** Personal-purchase BV dated inside the month — the §8 gate's only condition. */
function requalSeedMonthlyBv(int $distributorId, int $bvPaise, string $date): void
{
    static $fakeOrderId = 960000;

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 10:00:00',
        'created_at' => $date.' 10:00:00',
        'updated_at' => $date.' 10:00:00',
    ]);
}

/** A repurchase-wallet movement in the ledger — which the §8 gate no longer reads. */
function requalSeedWalletEntry(int $distributorId, int $amountPaise, string $type, string $createdAt): void
{
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'type' => $type,
        'amount_paise' => $type === 'repurchase_wallet_used' ? -abs($amountPaise) : abs($amountPaise),
        'reference_id' => null,
        'reference_type' => null,
        'memo' => 'test',
        'created_at' => $createdAt,
    ]);
}

/** Enough June BV to clear rank 1's monthly repurchase obligation. */
function requalSeedSufficientBv(int $distributorId): void
{
    requalSeedMonthlyBv(
        $distributorId,
        app(CompensationPlanSettingsService::class)->rankRepurchaseBvPaise(1),
        '2026-06-10',
    );
}

// §8 requalification: the month's own purchase BV ≥ the rank's repurchase BV.
// No wallet condition since the user's decision of 2026-10-17.

it('passes a distributor with enough BV in the month', function (): void {
    $dist = Distributor::factory()->create();
    requalSeedSufficientBv($dist->id);

    expect(app(RankRequalificationGateService::class)->passMap([$dist->id], Carbon::parse('2026-06-01'), 1)[$dist->id])
        ->toBeTrue();
});

it('passes a distributor still holding repurchase wallet money at month end — there is no wallet condition', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    requalSeedSufficientBv($dist->id);
    requalSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-06-20 09:00:00');

    expect(app(RankRequalificationGateService::class)->passMap([$dist->id], Carbon::parse('2026-06-01'), 1)[$dist->id])
        ->toBeTrue();
});

it('fails a distributor short of the rank\'s monthly repurchase BV', function (): void {
    $dist = Distributor::factory()->create();
    requalSeedMonthlyBv($dist->id, 99_900, '2026-06-10'); // 999 BV < Rank 1's 1,000

    expect(app(RankRequalificationGateService::class)->passMap([$dist->id], Carbon::parse('2026-06-01'), 1)[$dist->id])
        ->toBeFalse();
});

it('counts only the month\'s own BV — not the month before or after', function (): void {
    $dist = Distributor::factory()->create();
    requalSeedMonthlyBv($dist->id, 100_000, '2026-05-31');
    requalSeedMonthlyBv($dist->id, 100_000, '2026-07-01');

    expect(app(RankRequalificationGateService::class)->passMap([$dist->id], Carbon::parse('2026-06-01'), 1)[$dist->id])
        ->toBeFalse();
});

it('reads each distributor against the obligation of their own rank', function (): void {
    $rank1 = Distributor::factory()->create();
    $rank3 = Distributor::factory()->create();
    requalSeedMonthlyBv($rank1->id, 100_000, '2026-06-10'); // 1,000 BV
    requalSeedMonthlyBv($rank3->id, 100_000, '2026-06-10'); // 1,000 BV < Rank 3's 1,200

    $map = app(RankRequalificationGateService::class)->passMapByRank(
        [$rank1->id => 1, $rank3->id => 3],
        Carbon::parse('2026-06-01'),
    );

    expect($map)->toBe([$rank1->id => true, $rank3->id => false]);
});

it('shows the month a distributor is still living in as it stands, wallet balance or not', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    $thisMonth = Carbon::now()->startOfMonth();

    requalSeedMonthlyBv(
        $dist->id,
        app(CompensationPlanSettingsService::class)->rankRepurchaseBvPaise(1),
        Carbon::now()->toDateString(),
    );
    requalSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', Carbon::now()->subHour()->toDateTimeString());

    expect(app(RankRequalificationGateService::class)->passesSoFar($dist->id, $thisMonth, 1))
        ->toBeTrue();
});

/** A refund of an earlier purchase, recorded on $date — what BvLedgerService::reverse() writes. */
function requalSeedReversal(int $distributorId, int $orderId, int $bvPaise, string $date): void
{
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $orderId,
        'bv_paise' => -abs($bvPaise),
        'type' => 'reversal',
        'effective_at' => $date.' 10:00:00',
        'created_at' => $date.' 10:00:00',
        'updated_at' => $date.' 10:00:00',
    ]);
}

it('fails the gate when the month\'s qualifying purchase was cancelled (user decision 2026-10-17)', function (): void {
    $dist = Distributor::factory()->create();
    $obligation = app(CompensationPlanSettingsService::class)->rankRepurchaseBvPaise(1);

    requalSeedMonthlyBv($dist->id, $obligation, '2026-06-02');
    $orderId = (int) DB::table('bv_ledger_entries')->where('distributor_id', $dist->id)->value('order_id');
    requalSeedReversal($dist->id, $orderId, $obligation, '2026-06-05');

    $gate = app(RankRequalificationGateService::class);

    expect($gate->monthlyPersonalBvSoFarPaise($dist->id, Carbon::parse('2026-06-01')))->toBe(0)
        ->and($gate->passMap([$dist->id], Carbon::parse('2026-06-01'), 1)[$dist->id])->toBeFalse();
});

it('takes a refund off the month of the purchase it returns, not the month it is recorded in', function (): void {
    $dist = Distributor::factory()->create();
    $obligation = app(CompensationPlanSettingsService::class)->rankRepurchaseBvPaise(1);

    // A May purchase refunded in June leaves June untouched and takes May down.
    requalSeedMonthlyBv($dist->id, $obligation, '2026-05-20');
    $mayOrder = (int) DB::table('bv_ledger_entries')->where('distributor_id', $dist->id)->value('order_id');
    requalSeedReversal($dist->id, $mayOrder, $obligation, '2026-06-03');
    requalSeedMonthlyBv($dist->id, $obligation, '2026-06-10');

    $gate = app(RankRequalificationGateService::class);

    expect($gate->monthlyPersonalBvSoFarPaise($dist->id, Carbon::parse('2026-06-01')))->toBe($obligation)
        ->and($gate->monthlyPersonalBvSoFarPaise($dist->id, Carbon::parse('2026-05-01')))->toBe(0);
});
