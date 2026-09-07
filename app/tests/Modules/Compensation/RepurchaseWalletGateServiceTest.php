<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\RepurchaseWalletGateService;
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

/**
 * Seed a repurchase-wallet movement. A `repurchase_deduction` is money moved
 * INTO the repurchase wallet at bonus credit time; `repurchase_wallet_used` is
 * the distributor spending it on a repurchase order.
 */
function gateSeedWalletEntry(int $distributorId, int $amountPaise, string $type, string $createdAt): void
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

it('reports everyone clear when the repurchase engine is off', function (): void {
    $dist = Distributor::factory()->create();
    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-06-10 09:00:00');

    $map = app(RepurchaseWalletGateService::class)
        ->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-06-01'));

    expect($map[$dist->id])->toBeTrue();
});

it('blocks a distributor still holding repurchase wallet money at the last instant of the month', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $blocked = Distributor::factory()->create();
    $clear = Distributor::factory()->create();

    gateSeedWalletEntry($blocked->id, 50_000, 'repurchase_deduction', '2026-06-10 09:00:00');

    // Credited and spent within the month — nothing left at 23:59:59 on the 30th.
    gateSeedWalletEntry($clear->id, 50_000, 'repurchase_deduction', '2026-06-10 09:00:00');
    gateSeedWalletEntry($clear->id, 50_000, 'repurchase_wallet_used', '2026-06-28 09:00:00');

    $map = app(RepurchaseWalletGateService::class)
        ->clearedAtMonthEnd([$blocked->id, $clear->id], Carbon::parse('2026-06-15'));

    expect($map[$blocked->id])->toBeFalse()
        ->and($map[$clear->id])->toBeTrue();
});

it('judges the last instant of the month — a deduction created on the 1st of the next month does not count', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();

    // The June run credits a bonus on 1 July; the deduction it takes lands
    // AFTER June closed, so it can never block June.
    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-07-01 00:06:00');

    $june = app(RepurchaseWalletGateService::class)
        ->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-06-01'));
    $july = app(RepurchaseWalletGateService::class)
        ->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-07-01'));

    expect($june[$dist->id])->toBeTrue()
        ->and($july[$dist->id])->toBeFalse();
});

it('counts a deduction taken in the month\'s final second', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    gateSeedWalletEntry($dist->id, 1, 'repurchase_deduction', '2026-06-30 23:59:59');

    $map = app(RepurchaseWalletGateService::class)
        ->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-06-01'));

    expect($map[$dist->id])->toBeFalse();
});

it('returns a verdict for every id asked about, including one with no ledger at all', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();

    $map = app(RepurchaseWalletGateService::class)
        ->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-06-01'));

    expect($map)->toHaveKey($dist->id)
        ->and($map[$dist->id])->toBeTrue();
});

it('returns an empty map for an empty id list', function (): void {
    expect(app(RepurchaseWalletGateService::class)->clearedAtMonthEnd([], Carbon::parse('2026-06-01')))
        ->toBe([]);
});
