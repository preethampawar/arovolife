<?php

declare(strict_types=1);

/**
 * LOG-2: a bank_account_enc ciphertext that no longer decrypts used to return
 * null silently, sending a NEFT line out with a blank account number. It now
 * raises a `critical` log and holds THAT distributor's payout — everyone else
 * in the batch still pays out.
 */

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/** A distributor past the Retailer BV gate, with the given bank ciphertext. */
function pbdDistributor(string $bankEnc): Distributor
{
    $dist = Distributor::factory()->create();
    BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => 950_000 + $dist->id,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);
    DB::table('distributors')->where('id', $dist->id)->update(['bank_account_enc' => $bankEnc]);

    return $dist;
}

it('LOG-2-01: a corrupt ciphertext holds that distributor and logs critical, weekly batch', function (): void {
    $broken = pbdDistributor('not-valid-ciphertext');
    $healthy = pbdDistributor(PiiCrypter::encryptString('123456789012'));

    $wallet = app(WalletService::class);
    $wallet->credit($broken->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');
    $wallet->credit($healthy->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

    Log::spy();

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    $brokenLine = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $broken->id)->first();
    expect($brokenLine)->not->toBeNull()
        ->and($brokenLine->status)->toBe(PayoutLineItem::STATUS_BANK_DECRYPT_FAILED)
        ->and($brokenLine->net_transferred_paise)->toBe(0)
        ->and($brokenLine->failure_reason)->not->toBeNull();

    // The held wallet entries are never swept — the balance stays payable.
    expect(WalletLedgerEntry::where('distributor_id', $broken->id)->whereNotNull('swept_by_payout_batch_id')->count())->toBe(0)
        ->and($wallet->balancePaise($broken->id))->toBe(100_000);

    // Per-distributor isolation: the healthy distributor still pays out.
    $healthyLine = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $healthy->id)->first();
    expect($healthyLine->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and($healthyLine->bank_account_last4)->toBe('9012');

    Log::shouldHaveReceived('critical')->withArgs(
        fn (string $message, array $context = []) => ($context['context'] ?? null) === 'bank_decryption_failure'
            && ($context['distributor_id'] ?? null) === $broken->id
    )->once();
});

it('LOG-2-02: the same hold applies in the monthly batch', function (): void {
    $broken = pbdDistributor('not-valid-ciphertext');

    app(WalletService::class)->credit($broken->id, 500_000, 'gbb_credit', walletRef(), 'test_reference');

    $batch = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $broken->id)->first();
    expect($line)->not->toBeNull()
        ->and($line->status)->toBe(PayoutLineItem::STATUS_BANK_DECRYPT_FAILED)
        ->and($line->net_transferred_paise)->toBe(0);

    expect(app(WalletService::class)->balancePaise($broken->id))->toBe(500_000);
});
