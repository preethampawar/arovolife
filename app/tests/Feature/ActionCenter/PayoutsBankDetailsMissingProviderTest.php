<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\PayoutsBankDetailsMissingProvider;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(PayoutsBankDetailsMissingProvider::class);
});

it('counts a never-attempted earner with payable income and no bank record', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::personalBv($distributor->id);
    Helpers::payableIncome($distributor->id, 75_000, 'gsb_credit', now()->subDays(3));

    expect($this->provider->count())->toBe(1);

    $item = $this->provider->items()->first();
    expect($item->subjectId)->toBe($distributor->id);
    expect($item->meta['unswept_gross_paise'])->toBe(75_000);
});

it('ignores a distributor with a bank account on file', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    $distributor->forceFill(['bank_account_enc' => 'some-real-ciphertext'])->save();
    Helpers::personalBv($distributor->id);
    Helpers::payableIncome($distributor->id);

    expect($this->provider->count())->toBe(0);
});

it('treats the stub placeholder as no bank record', function (): void {
    $distributor = Helpers::distributorWithoutBank(); // factory default is 'stub'
    Helpers::personalBv($distributor->id);
    Helpers::payableIncome($distributor->id);

    expect($this->provider->count())->toBe(1);
});

it('excludes a distributor whose KYC is not active', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    DB::table('users')->where('id', $distributor->user_id)->update(['status' => 'pending']);
    Helpers::personalBv($distributor->id);
    Helpers::payableIncome($distributor->id);

    expect($this->provider->count())->toBe(0);
});

it('excludes a distributor below the NEFT minimum BV', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::personalBv($distributor->id, 299_999);
    Helpers::payableIncome($distributor->id);

    expect($this->provider->count())->toBe(0);
});

it('includes a distributor exactly at the NEFT minimum BV', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::personalBv($distributor->id, 300_000);
    Helpers::payableIncome($distributor->id);

    expect($this->provider->count())->toBe(1);
});

it('ignores a credit already swept by a payout batch', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::personalBv($distributor->id);
    $income = Helpers::payableIncome($distributor->id);
    $income->forceFill(['swept_by_payout_batch_id' => 1])->save();

    expect($this->provider->count())->toBe(0);
});

it('ignores a credit that has been reversed', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::personalBv($distributor->id);
    $income = Helpers::payableIncome($distributor->id);

    WalletLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'type' => 'reversal',
        'amount_paise' => -$income->amount_paise,
        'reference_type' => $income->reference_type,
        'reference_id' => $income->reference_id,
    ]);

    expect($this->provider->count())->toBe(0);
});

it('ignores a manual credit — not a payable bonus type', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::personalBv($distributor->id);
    Helpers::payableIncome($distributor->id, 50_000, 'manual_credit');

    expect($this->provider->count())->toBe(0);
});

it('ignores a non-positive credit', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::personalBv($distributor->id);
    Helpers::payableIncome($distributor->id, 0);

    expect($this->provider->count())->toBe(0);
});

it('orders items by the oldest waiting income first', function (): void {
    $older = Helpers::distributorWithoutBank();
    Helpers::personalBv($older->id);
    Helpers::payableIncome($older->id, 10_000, 'gsb_credit', now()->subDays(10));

    $newer = Helpers::distributorWithoutBank();
    Helpers::personalBv($newer->id);
    Helpers::payableIncome($newer->id, 10_000, 'gsb_credit', now()->subDays(1));

    $items = $this->provider->items();

    expect($items)->toHaveCount(2);
    expect($items->first()->subjectId)->toBe($older->id);
    expect($items->last()->subjectId)->toBe($newer->id);
});

it('excludes a snoozed distributor', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::personalBv($distributor->id);
    Helpers::payableIncome($distributor->id);

    ActionCenterSnooze::create([
        'action_key' => 'payouts.bank_details_missing',
        'subject_type' => 'distributor',
        'subject_id' => $distributor->id,
        'snoozed_until' => now()->addDays(3),
        'reason' => 'Distributor asked for a week to add bank details.',
    ]);

    expect($this->provider->count())->toBe(0);
});

it('drops out once a real bank account is added, without waiting for a new payout batch', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::personalBv($distributor->id);
    Helpers::payableIncome($distributor->id);

    expect($this->provider->count())->toBe(1);

    $distributor->forceFill(['bank_account_enc' => 'some-real-ciphertext'])->save();

    expect($this->provider->count())->toBe(0);
});
