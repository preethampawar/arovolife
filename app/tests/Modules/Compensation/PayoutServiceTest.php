<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('pays out a wallet balance above minimum', function () {
    $dist = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit'); // ₹1,000

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch(Carbon::today());

    expect($batch->status)->toBe(PayoutBatch::STATUS_COMPLETED);
    expect($batch->distributor_count)->toBe(1);

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED);
    expect($line->net_transferred_paise)->toBe(100_000); // no repurchase deduction (no prior month credits)

    // Wallet should be zero after payout
    expect($walletSvc->balancePaise($dist->id))->toBe(0);
});

it('skips wallet below minimum payout threshold', function () {
    $dist = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 40_000, 'gsb_credit'); // ₹400 — below ₹500 minimum

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch(Carbon::today());

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_BELOW_MINIMUM);

    // Wallet should still have the balance
    expect($walletSvc->balancePaise($dist->id))->toBe(40_000);
});

it('is idempotent — running twice returns the same completed batch', function () {
    $dist = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit');

    $svc = app(PayoutService::class);
    $batch1 = $svc->runBatch(Carbon::today());
    $batch2 = $svc->runBatch(Carbon::today());

    expect($batch1->id)->toBe($batch2->id);
    expect(PayoutBatch::count())->toBe(1);
    // Wallet should only be debited once
    expect($walletSvc->balancePaise($dist->id))->toBe(0);
});

it('applies repurchase deduction from prior month gsb and mb credits', function () {
    $dist = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);

    // Pin to the middle of the prior month so the credit is unambiguously in the prior period.
    $today = Carbon::create(2026, 7, 15, 12, 0, 0);
    $priorMonthMid = Carbon::create(2026, 6, 15, 12, 0, 0);

    // Credit prior-month GSB: ₹2,000 = 200,000 paise → 10% deduction = 20,000 paise
    Carbon::setTestNow($priorMonthMid);
    $walletSvc->credit($dist->id, 200_000, 'gsb_credit');

    // Credit current-month GSB: ₹1,000 = 100,000 paise (not included in deduction)
    Carbon::setTestNow($today);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit');

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch($today);

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED);
    expect($line->repurchase_deduction_paise)->toBe(20_000); // 10% of prior-month ₹2,000

    // wallet_balance_paise = 300,000; payout_debit = -300,000; repurchase_deduction credit = +20,000
    // final balance = 300,000 - 300,000 + 20,000 = 20,000 paise (the deduction is returned to wallet)
    expect($walletSvc->balancePaise($dist->id))->toBe(20_000);

    Carbon::setTestNow(null);
});

it('skips distributors with zero or negative wallet balance', function () {
    $dist = Distributor::factory()->create();
    // No wallet credits — balance is 0

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch(Carbon::today());

    expect($batch->distributor_count)->toBe(0);
    expect(PayoutLineItem::where('distributor_id', $dist->id)->count())->toBe(0);
});

it('accumulates totals correctly across multiple distributors', function () {
    $dist1 = Distributor::factory()->create();
    $dist2 = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist1->id, 100_000, 'gsb_credit'); // ₹1,000
    $walletSvc->credit($dist2->id, 200_000, 'gsb_credit'); // ₹2,000

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch(Carbon::today());

    expect($batch->distributor_count)->toBe(2);
    expect($batch->total_gross_paise)->toBe(300_000);
    expect($batch->total_net_paise)->toBe(300_000); // no repurchase deductions
    expect($batch->status)->toBe(PayoutBatch::STATUS_COMPLETED);
});
