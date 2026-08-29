<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('balance returns 0 for new distributor', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    expect($svc->balancePaise($dist->id))->toBe(0);
});

it('credit adds a positive entry', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $svc->credit($dist->id, 100_000, 'gsb_credit', 1, 'gsb_cutoff_result', 'GSB for 24 Jun');
    expect($svc->balancePaise($dist->id))->toBe(100_000);
});

it('leaves engine_run_id null outside an engine run', function () {
    // Order-time and admin-correction entries have no run to attribute them to;
    // only the Run events "Ledger" column depends on the distinction.
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);

    expect($svc->credit($dist->id, 100_000, 'manual_credit')->engine_run_id)->toBeNull();
    expect($svc->debit($dist->id, 40_000, 'payout_debit')->engine_run_id)->toBeNull();
});

it('debit subtracts from balance', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $svc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');
    $svc->debit($dist->id, 40_000, 'payout_debit');
    expect($svc->balancePaise($dist->id))->toBe(60_000);
});

it('balance is the sum of all signed entries', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $svc->credit($dist->id, 552_900, 'gsb_credit', walletRef(), 'test_reference');    // ₹5,529
    $svc->credit($dist->id, 27_640, 'mb_credit', walletRef(), 'test_reference');      // ₹276.40
    $svc->debit($dist->id, 552_900, 'payout_debit');
    expect($svc->balancePaise($dist->id))->toBe(27_640);
});
