<?php

declare(strict_types=1);

/**
 * A batch is sent one line per job, so a killed job strands one line and that
 * line is left retryable.
 *
 * PF-01: the batch job queues one line job per payable, unsent, pending line
 * PF-02: the line job sends through the dispatch service and settles the batch
 * PF-03: failed() marks a still-unsent line failed (job_interrupted), never one with an id
 * PF-04: the line job does nothing once the batch is no longer dispatched
 */

use App\Modules\Compensation\Jobs\DispatchRazorpayPayoutLineJob;
use App\Modules\Compensation\Jobs\DispatchRazorpayPayoutsJob;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\RazorpayPayoutDispatchService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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

/** An approved batch in the dispatched state with one pending line. */
function pfoBatch(string $adn): array
{
    [$batch, $line] = reconcileFixture($adn);
    $batch->forceFill(['status' => PayoutBatch::STATUS_DISPATCHED])->save();

    Distributor::whereKey($line->distributor_id)->update([
        'razorpay_contact_id' => 'cont_'.$adn,
        'bank_ifsc' => 'HDFC0000001',
        'bank_account_enc' => PiiCrypter::encryptString('50100012345678'),
    ]);

    return [$batch, $line];
}

function pfoLine(PayoutBatch $batch, string $adn, array $attributes = []): PayoutLineItem
{
    return PayoutLineItem::create(array_merge([
        'payout_batch_id' => $batch->id,
        'distributor_id' => Distributor::factory()->create(['adn' => $adn])->id,
        'wallet_balance_paise' => 50_000,
        'gross_paise' => 50_000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 50_000,
        'status' => PayoutLineItem::STATUS_PENDING,
    ], $attributes));
}

function pfoRunLine(int $lineId): void
{
    (new DispatchRazorpayPayoutLineJob($lineId))->handle(
        app(RazorpayPayoutDispatchService::class),
        app(PayoutGatewaySettings::class),
    );
}

it('PF-01: the batch job queues one line job per payable unsent pending line', function (): void {
    Queue::fake();
    [$batch, $payable] = pfoBatch('ADN9201');
    $second = pfoLine($batch, 'ADN9202');
    pfoLine($batch, 'ADN9203', ['status' => PayoutLineItem::STATUS_FAILED]);
    pfoLine($batch, 'ADN9204', ['status' => PayoutLineItem::STATUS_TRANSFERRED]);
    pfoLine($batch, 'ADN9205', ['status' => PayoutLineItem::STATUS_KYC_PENDING]);
    pfoLine($batch, 'ADN9206', ['razorpay_payout_id' => 'pout_sent']);
    pfoLine($batch, 'ADN9207', ['net_transferred_paise' => 0]);

    (new DispatchRazorpayPayoutsJob((int) $batch->id))->handle(
        app(RazorpayPayoutDispatchService::class),
        app(PayoutGatewaySettings::class),
    );

    Queue::assertPushed(DispatchRazorpayPayoutLineJob::class, 2);
    Queue::assertPushed(DispatchRazorpayPayoutLineJob::class, fn ($job): bool => $job->lineItemId === (int) $payable->id);
    Queue::assertPushed(DispatchRazorpayPayoutLineJob::class, fn ($job): bool => $job->lineItemId === (int) $second->id);

    $audit = AuditLog::where('action', 'payout.batch.dispatched')->where('subject_id', $batch->id)->sole();
    expect($audit->details['line_items_queued'])->toBe(2)
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_DISPATCHED);
});

it('PF-02: the line job sends through the dispatch service and settles its batch', function (): void {
    [$batch, $line] = pfoBatch('ADN9211');
    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/fund_accounts') => Http::response(['items' => [[
                'id' => 'fa_1', 'account_type' => 'bank_account', 'active' => true,
                'bank_account' => ['ifsc' => 'HDFC0000001', 'account_number' => '50100012345678'],
            ]]]),
            $request->method() === 'GET' => Http::response(['items' => []]),
            default => Http::response(['id' => 'pout_done', 'status' => 'processed', 'utr' => 'UTR9211']),
        };
    });

    pfoRunLine((int) $line->id);

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED)
        ->and($line->fresh()->razorpay_payout_id)->toBe('pout_done')
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_COMPLETED);
});

it('PF-03: failed() marks a still-unsent line failed, and leaves a line with a payout id alone', function (): void {
    [$batch, $unsent] = pfoBatch('ADN9221');
    $sent = pfoLine($batch, 'ADN9222', ['razorpay_payout_id' => 'pout_live']);

    (new DispatchRazorpayPayoutLineJob((int) $unsent->id))->failed(new RuntimeException('timed out'));
    (new DispatchRazorpayPayoutLineJob((int) $sent->id))->failed(new RuntimeException('timed out'));

    $audit = AuditLog::where('action', 'payout.line_item.dispatch_failed')->where('subject_id', $unsent->id)->sole();

    expect($unsent->fresh()->status)->toBe(PayoutLineItem::STATUS_FAILED)
        ->and($audit->details['cause'])->toBe('job_interrupted')
        ->and($sent->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and(AuditLog::where('action', 'payout.line_item.dispatch_failed')->where('subject_id', $sent->id)->exists())->toBeFalse();
});

it('PF-04: the line job does nothing once the batch is no longer dispatched', function (): void {
    Http::fake();
    [$batch, $line] = pfoBatch('ADN9231');
    $batch->forceFill(['status' => PayoutBatch::STATUS_APPROVED])->save();

    pfoRunLine((int) $line->id);

    Http::assertNothingSent();
    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and($line->fresh()->razorpay_payout_id)->toBeNull();
});
