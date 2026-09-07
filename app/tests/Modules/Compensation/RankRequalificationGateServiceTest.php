<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RepurchaseCycle;
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

/** Personal-purchase BV dated inside the month — condition (a) of the §8 gate. */
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

/** A repurchase-wallet movement in the ledger — condition (b) of the §8 gate. */
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

it('passes a distributor with enough BV and an empty repurchase wallet at month end', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    requalSeedSufficientBv($dist->id);

    expect(app(RankRequalificationGateService::class)->passes($dist->id, Carbon::parse('2026-06-01'), 1))
        ->toBeTrue();
});

it('reads the wallet condition as the month-end balance, not the cycle verdict', function (): void {
    // Client 2026-09-05, re-confirmed 2026-09-07. The repurchase CYCLE no
    // longer decides this gate: a distributor whose cycle lapsed but whose
    // repurchase wallet is empty at month end meets condition (b).
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    requalSeedSufficientBv($dist->id);

    RepurchaseCycle::create([
        'distributor_id' => $dist->id,
        'cycle_start_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'wallet_balance_paise' => 0,
        'wallet_zeroed' => true,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
        'failure_reason' => RepurchaseCycle::REASON_BV_SHORT,
        'resolved_at' => Carbon::parse('2026-06-01 00:05:00'),
    ]);

    expect(app(RankRequalificationGateService::class)->passes($dist->id, Carbon::parse('2026-06-01'), 1))
        ->toBeTrue();
});

it('fails a distributor still holding repurchase wallet money at month end, even on a completed cycle', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    requalSeedSufficientBv($dist->id);
    requalSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-06-20 09:00:00');

    RepurchaseCycle::create([
        'distributor_id' => $dist->id,
        'cycle_start_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 60_000,
        'wallet_balance_paise' => 0,
        'wallet_zeroed' => true,
        'status' => RepurchaseCycle::STATUS_COMPLETED,
        'fulfilled_on' => '2026-05-20',
        'failure_reason' => null,
        'resolved_at' => Carbon::parse('2026-06-01 00:05:00'),
    ]);

    expect(app(RankRequalificationGateService::class)->passes($dist->id, Carbon::parse('2026-06-01'), 1))
        ->toBeFalse();
});

it('ignores a repurchase-wallet balance that only appears after the month closed', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    requalSeedSufficientBv($dist->id);

    // The June run credits a bonus on 1 July; the deduction it takes lands
    // after June closed and cannot retroactively fail June.
    requalSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-07-01 00:06:00');

    expect(app(RankRequalificationGateService::class)->passes($dist->id, Carbon::parse('2026-06-01'), 1))
        ->toBeTrue();
});

it('fails a distributor short of the rank\'s monthly repurchase BV whatever the wallet says', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();

    expect(app(RankRequalificationGateService::class)->passes($dist->id, Carbon::parse('2026-06-01'), 1))
        ->toBeFalse();
});

it('leaves the wallet condition open when the repurchase engine is off', function (): void {
    $dist = Distributor::factory()->create();
    requalSeedSufficientBv($dist->id);
    requalSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-06-20 09:00:00');

    expect(app(RankRequalificationGateService::class)->passes($dist->id, Carbon::parse('2026-06-01'), 1))
        ->toBeTrue();
});
