<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('generates a PENDING batch after run — wallets debited, awaiting admin approval', function () {
    $dist = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit'); // ₹1,000

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch(Carbon::today());

    // Batch is PENDING (not yet approved by admin).
    expect($batch->status)->toBe(PayoutBatch::STATUS_PENDING);
    expect($batch->processed_at)->not->toBeNull();
    expect($batch->distributor_count)->toBe(1);

    // Line item is PENDING (awaiting NEFT confirmation).
    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING);
    expect($line->net_transferred_paise)->toBe(100_000);

    // Wallet IS debited immediately during generation to prevent double-spend.
    expect($walletSvc->balancePaise($dist->id))->toBe(0);
});

it('approve() marks batch COMPLETED and line items TRANSFERRED', function () {
    $admin = User::factory()->create();
    $dist = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit');

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch(Carbon::today());

    $approved = $svc->approve($batch, $admin->id);

    expect($approved->status)->toBe(PayoutBatch::STATUS_COMPLETED);
    expect($approved->approved_by)->toBe($admin->id);
    expect($approved->approved_at)->not->toBeNull();

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED);
});

it('skips wallet below minimum payout threshold', function () {
    $dist = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 40_000, 'gsb_credit'); // ₹400 — below ₹500 minimum

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch(Carbon::today());

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_BELOW_MINIMUM);

    // Wallet still has the balance (no debit for below-minimum).
    expect($walletSvc->balancePaise($dist->id))->toBe(40_000);
});

it('is idempotent — running twice returns the same batch without double-debiting', function () {
    $dist = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit');

    $svc = app(PayoutService::class);
    $batch1 = $svc->runBatch(Carbon::today());
    $batch2 = $svc->runBatch(Carbon::today());

    expect($batch1->id)->toBe($batch2->id);
    expect(PayoutBatch::count())->toBe(1);
    // Wallet only debited once.
    expect($walletSvc->balancePaise($dist->id))->toBe(0);
    // Only one line item created.
    expect(PayoutLineItem::where('distributor_id', $dist->id)->count())->toBe(1);
});

it('applies repurchase deduction from prior month gsb and mb credits', function () {
    $dist = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);

    $today = Carbon::create(2026, 7, 15, 12, 0, 0);
    $priorMonthMid = Carbon::create(2026, 6, 15, 12, 0, 0);

    // Credit prior-month GSB: ₹2,000 → 10% deduction = 20,000 paise.
    Carbon::setTestNow($priorMonthMid);
    $walletSvc->credit($dist->id, 200_000, 'gsb_credit');

    // Credit current-month GSB: ₹1,000 (not included in deduction).
    Carbon::setTestNow($today);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit');

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch($today);

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING);
    expect($line->repurchase_deduction_paise)->toBe(20_000);

    // After debit and repurchase credit: net wallet = 20,000 (the held-back deduction).
    expect($walletSvc->balancePaise($dist->id))->toBe(20_000);

    Carbon::setTestNow(null);
});

it('skips distributors with zero wallet balance', function () {
    $dist = Distributor::factory()->create();

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch(Carbon::today());

    expect($batch->distributor_count)->toBe(0);
    expect(PayoutLineItem::where('distributor_id', $dist->id)->count())->toBe(0);
});

it('accumulates totals correctly across multiple distributors', function () {
    $dist1 = Distributor::factory()->create();
    $dist2 = Distributor::factory()->create();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist1->id, 100_000, 'gsb_credit');
    $walletSvc->credit($dist2->id, 200_000, 'gsb_credit');

    $svc = app(PayoutService::class);
    $batch = $svc->runBatch(Carbon::today());

    expect($batch->distributor_count)->toBe(2);
    expect($batch->total_gross_paise)->toBe(300_000);
    expect($batch->total_net_paise)->toBe(300_000);
    expect($batch->status)->toBe(PayoutBatch::STATUS_PENDING);
});
