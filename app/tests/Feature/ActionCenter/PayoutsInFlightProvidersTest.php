<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Providers\Money\PayoutsAwaitingBankConfirmationProvider;
use App\Modules\ActionCenter\Providers\Money\PayoutsUnsentInDispatchedBatchProvider;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/** A payable line in a batch of the given status approved $approvedHoursAgo. */
function acInFlightLine(string $batchStatus, int $approvedHoursAgo, array $line = []): PayoutLineItem
{
    $batch = Helpers::payoutBatch($batchStatus, ['approved_at' => now()->subHours($approvedHoursAgo)]);

    return PayoutLineItem::create(array_merge([
        'payout_batch_id' => $batch->id,
        'distributor_id' => Distributor::factory()->create()->id,
        'gross_paise' => 125_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'wallet_balance_paise' => 125_000,
        'repurchase_deduction_paise' => 0,
        'net_transferred_paise' => 125_000,
        'status' => PayoutLineItem::STATUS_PENDING,
    ], $line));
}

it('lists a transfer with Razorpay for over a day, and not one sent within the day', function (): void {
    $provider = app(PayoutsAwaitingBankConfirmationProvider::class);
    $old = acInFlightLine(PayoutBatch::STATUS_DISPATCHED, 30, ['razorpay_payout_id' => 'pout_old', 'dispatched_at' => now()->subHours(30)]);
    acInFlightLine(PayoutBatch::STATUS_DISPATCHED, 5, ['razorpay_payout_id' => 'pout_new', 'dispatched_at' => now()->subHours(5)]);
    acInFlightLine(PayoutBatch::STATUS_COMPLETED, 30, ['razorpay_payout_id' => 'pout_done', 'dispatched_at' => now()->subHours(30), 'status' => PayoutLineItem::STATUS_TRANSFERRED]);

    expect($provider->count())->toBe(1);

    $item = $provider->items()->sole();
    expect($item->subjectId)->toBe((int) $old->id)
        ->and($item->severity)->toBe(Severity::WARNING)
        ->and($item->title)->toContain('₹1,250.00')
        ->and($item->subtitle)->toContain('no confirmation yet')
        ->and($item->url)->toBe(route('admin.compensation.weekly-payouts.show', ['batch' => $old->payout_batch_id]));
});

it('lists a payable line never sent from a batch dispatched over an hour ago, and nothing else', function (): void {
    $provider = app(PayoutsUnsentInDispatchedBatchProvider::class);
    $stranded = acInFlightLine(PayoutBatch::STATUS_DISPATCHED, 3);
    acInFlightLine(PayoutBatch::STATUS_DISPATCHED, 0);                          // under the threshold
    acInFlightLine(PayoutBatch::STATUS_APPROVED, 3);                            // manual NEFT, not with Razorpay
    acInFlightLine(PayoutBatch::STATUS_DISPATCHED, 3, ['razorpay_payout_id' => 'pout_1']); // was sent
    acInFlightLine(PayoutBatch::STATUS_DISPATCHED, 3, ['net_transferred_paise' => 0]);    // nothing to send

    expect($provider->count())->toBe(1);

    $item = $provider->items()->sole();
    expect($item->subjectId)->toBe((int) $stranded->id)
        ->and($item->severity)->toBe(Severity::CRITICAL)
        ->and($item->subtitle)->toContain('never sent');
});
