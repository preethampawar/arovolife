<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Providers\Money\UnpayableManualCreditProvider;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(UnpayableManualCreditProvider::class);
});

function ledgerEntry(string $type, int $distributorId): WalletLedgerEntry
{
    return WalletLedgerEntry::create([
        'distributor_id' => $distributorId,
        'type' => $type,
        'amount_paise' => 150_000,
        'reference_id' => 1,
        'reference_type' => 'gsb_cutoff_result',
        'memo' => 'Legacy entry',
    ]);
}

it('is silent on an environment that never wrote one', function (): void {
    $distributor = Distributor::factory()->create();
    ledgerEntry('gsb_credit', $distributor->id);
    ledgerEntry('mb_credit', $distributor->id);

    // The writer was deleted on 2026-09-17, so this is what every clean
    // environment looks like: the provider exists and shows nothing.
    expect($this->provider->count())->toBe(0);
});

it('surfaces a legacy manual_credit with the distributor and the amount', function (): void {
    $distributor = Distributor::factory()->create();
    $entry = ledgerEntry('manual_credit', $distributor->id);

    $item = $this->provider->items()->first();

    expect($this->provider->count())->toBe(1)
        ->and($item->subjectId)->toBe($entry->id)
        ->and($item->title)->toContain($distributor->adn)
        ->and($item->meta['amount_paise'])->toBe(150_000);
});

it('acts under finance, the hand that would reconcile it', function (): void {
    expect($this->provider->permission())->toBe('finance.record');
});
