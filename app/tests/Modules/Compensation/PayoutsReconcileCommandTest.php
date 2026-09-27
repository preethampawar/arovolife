<?php

declare(strict_types=1);

/**
 * payouts:reconcile — the backstop for a webhook that never arrived and for a
 * line a killed job never sent.
 *
 * PR-01: a line with Razorpay past --hours is checked; processed → transferred, batch settles
 * PR-02: a line sent within --hours is left alone
 * PR-03: an unsent line in a dispatched batch is re-queued; one in an approved batch is not
 * PR-04: --dry-run changes nothing and queues nothing
 * PR-05: manual NEFT mode touches nothing
 */

use App\Modules\Compensation\Jobs\DispatchRazorpayPayoutLineJob;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    setGatewaySetting('payout.gateway', 'razorpay');
    config([
        'arovolife.payments.razorpay_payouts.key_id' => 'rzp_test_key',
        'arovolife.payments.razorpay_payouts.key_secret' => 'rzp_test_secret',
        'arovolife.payments.razorpay_payouts.account_number' => '2323230000000000',
        'arovolife.payments.razorpay_payouts.base_url' => 'https://api.razorpay.com/v1',
    ]);
    Http::preventStrayRequests();
});

/** One batch per date: each fixture takes the next day back. */
function prNextBatchDay(): int
{
    static $day = 0;

    return $day += 7;
}

/** A line with Razorpay, sent $hoursAgo, in a dispatched batch approved then. */
function prSentLine(string $adn, int $hoursAgo): array
{
    [$batch, $line] = reconcileFixture($adn, prNextBatchDay());
    $batch->forceFill(['status' => PayoutBatch::STATUS_DISPATCHED, 'approved_at' => now()->subHours($hoursAgo)])->save();
    $line->forceFill(['razorpay_payout_id' => 'pout_'.$adn, 'dispatched_at' => now()->subHours($hoursAgo)])->save();

    return [$batch, $line->fresh()];
}

/** A payable line never sent, in a batch of the given status approved $hoursAgo. */
function prUnsentLine(string $adn, int $hoursAgo, string $batchStatus = PayoutBatch::STATUS_DISPATCHED): PayoutLineItem
{
    [$batch, $line] = reconcileFixture($adn, prNextBatchDay());
    $batch->forceFill(['status' => $batchStatus, 'approved_at' => now()->subHours($hoursAgo)])->save();

    return $line;
}

it('PR-01: a transfer with Razorpay past --hours is checked and settled from the answer', function (): void {
    Queue::fake();
    [$batch, $line] = prSentLine('ADN9301', 7);
    Http::fake(['*/payouts/pout_ADN9301' => Http::response(['id' => 'pout_ADN9301', 'status' => 'processed', 'utr' => 'UTR9301'])]);

    $this->artisan('payouts:reconcile')
        ->expectsOutputToContain('Checked 1 with Razorpay (1 transferred, 0 failed, 0 still in flight, 0 unreachable); re-queued 0 unsent line(s).')
        ->assertExitCode(0);

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED)
        ->and($line->fresh()->utr_number)->toBe('UTR9301')
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_COMPLETED);
});

it('PR-02: a transfer sent within --hours is left alone', function (): void {
    Queue::fake();
    Http::fake();
    [, $line] = prSentLine('ADN9302', 1);

    $this->artisan('payouts:reconcile')->assertExitCode(0);

    Http::assertNothingSent();
    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING);
});

it('PR-03: an unsent line in a dispatched batch is re-queued; one in a batch not yet dispatched is not', function (): void {
    Queue::fake();
    $unsent = prUnsentLine('ADN9303', 7);
    prUnsentLine('ADN9304', 7, PayoutBatch::STATUS_APPROVED);

    $this->artisan('payouts:reconcile')
        ->expectsOutputToContain('re-queued 1 unsent line(s)')
        ->assertExitCode(0);

    Queue::assertPushed(DispatchRazorpayPayoutLineJob::class, 1);
    Queue::assertPushed(DispatchRazorpayPayoutLineJob::class, fn ($job): bool => $job->lineItemId === (int) $unsent->id);
});

it('PR-04: --dry-run changes nothing and queues nothing', function (): void {
    Queue::fake();
    Http::fake();
    [, $sent] = prSentLine('ADN9305', 7);
    prUnsentLine('ADN9306', 7);

    $this->artisan('payouts:reconcile', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: would check 1 with Razorpay and re-queue 1 unsent line(s).')
        ->assertExitCode(0);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
    expect($sent->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING);
});

it('PR-05: manual NEFT mode touches nothing', function (): void {
    setGatewaySetting('payout.gateway', 'manual_neft');
    Queue::fake();
    Http::fake();
    prSentLine('ADN9307', 7);
    prUnsentLine('ADN9308', 7);

    $this->artisan('payouts:reconcile')
        ->expectsOutputToContain('nothing to reconcile')
        ->assertExitCode(0);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('exits non-zero when Razorpay cannot be reached for any line', function (): void {
    Queue::fake();
    prSentLine('ADN9309', 7);
    Http::fake(['*/payouts/*' => Http::response(['error' => ['code' => 'SERVER_ERROR', 'description' => 'down']], 502)]);

    $this->artisan('payouts:reconcile')
        ->expectsOutputToContain('1 unreachable')
        ->assertExitCode(1);
});
