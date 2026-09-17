<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\PayoutsBankUndecryptableProvider;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function undecryptableProvider(): PayoutsBankUndecryptableProvider
{
    return app(PayoutsBankUndecryptableProvider::class);
}

/** A distributor who would be paid but for the state of their bank column. */
function undecryptableCandidate(string $bankColumn): int
{
    $distributor = Helpers::distributorWithoutBank();
    $distributor->forceFill(['bank_account_enc' => $bankColumn])->save();
    Helpers::personalBv($distributor->id);
    Helpers::payableIncome($distributor->id, 75_000, 'gsb_credit', now()->subDays(3));

    return $distributor->id;
}

it('flags a plaintext account number written straight into the ciphertext column', function (): void {
    $id = undecryptableCandidate('50100234567890');

    expect(undecryptableProvider()->count())->toBe(1)
        ->and(undecryptableProvider()->items()->first()->subjectId)->toBe($id);
});

it('leaves a real ciphertext alone', function (): void {
    undecryptableCandidate(PiiCrypter::encryptString('50100234567890'));

    expect(undecryptableProvider()->count())->toBe(0);
});

it('never reports the same distributor as the missing-details provider', function (): void {
    // 'stub', empty and null all belong to PayoutsBankDetailsMissingProvider.
    foreach (['stub', ''] as $absent) {
        undecryptableCandidate($absent);
    }

    $nulled = Helpers::distributorWithoutBank();
    $nulled->forceFill(['bank_account_enc' => null])->save();
    Helpers::personalBv($nulled->id);
    Helpers::payableIncome($nulled->id);

    expect(undecryptableProvider()->count())->toBe(0);
});

it('flags a well-formed ciphertext the payout engine has already failed to read', function (): void {
    $id = undecryptableCandidate(PiiCrypter::encryptString('50100234567890'));

    $batch = Helpers::payoutBatch(PayoutBatch::STATUS_PENDING);
    PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $id,
        'gross_paise' => 50_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'wallet_balance_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_transferred_paise' => 0,
        'status' => PayoutLineItem::STATUS_BANK_DECRYPT_FAILED,
        'retry_count' => 0,
    ]);

    expect(undecryptableProvider()->count())->toBe(1);
});

it('lets go once the batch that held them is completed', function (): void {
    $id = undecryptableCandidate(PiiCrypter::encryptString('50100234567890'));

    $batch = Helpers::payoutBatch(PayoutBatch::STATUS_COMPLETED);
    PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $id,
        'gross_paise' => 50_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'wallet_balance_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_transferred_paise' => 0,
        'status' => PayoutLineItem::STATUS_BANK_DECRYPT_FAILED,
        'retry_count' => 0,
    ]);

    expect(undecryptableProvider()->count())->toBe(0);
});

it('excludes a distributor whose KYC is not active', function (): void {
    $id = undecryptableCandidate('50100234567890');
    $userId = DB::table('distributors')->where('id', $id)->value('user_id');
    DB::table('users')->where('id', $userId)->update(['status' => 'pending']);

    expect(undecryptableProvider()->count())->toBe(0);
});

it('excludes a snoozed distributor', function (): void {
    $id = undecryptableCandidate('50100234567890');

    ActionCenterSnooze::create([
        'action_key' => 'payouts.bank_undecryptable',
        'subject_type' => 'distributor',
        'subject_id' => $id,
        'snoozed_until' => now()->addDay(),
        'reason' => 'Re-capture booked for Monday.',
    ]);

    expect(undecryptableProvider()->count())->toBe(0);
});
