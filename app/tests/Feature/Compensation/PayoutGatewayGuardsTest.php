<?php

declare(strict_types=1);

/**
 * The guards that stand between an approved batch and someone's bank account.
 *
 * Every case here is one where getting it wrong costs real money or leaks a
 * bank account number, so they are asserted directly rather than through the
 * dispatch path.
 */

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\PayoutReconciliationService;
use App\Modules\Compensation\Services\RazorpayPayoutGateway;
use App\Modules\Compensation\Support\RazorpayPayoutPayloadScrubber;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function setGatewaySetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

// ── Gateway settings ───────────────────────────────────────────────────

it('falls back to manual NEFT when the stored gateway value is not recognised', function (): void {
    // A typo in the settings table must never be read as "start moving money".
    setGatewaySetting('payout.gateway', 'razorpayy');

    expect(app(PayoutGatewaySettings::class)->gateway())->toBe('manual_neft')
        ->and(app(PayoutGatewaySettings::class)->isRazorpay())->toBeFalse();
});

it('sends an IMPS transfer above the ₹5,00,000 NPCI cap on NEFT instead', function (): void {
    setGatewaySetting('payout.razorpay.transfer_mode', 'IMPS');
    $settings = app(PayoutGatewaySettings::class);

    // Exactly at the cap is still IMPS; a rupee over is not.
    expect($settings->modeFor(50_000_000))->toBe('IMPS')
        ->and($settings->modeFor(50_000_100))->toBe('NEFT');
});

it('reads a gateway switch without restarting — nothing is cached in process', function (): void {
    // The compensation worker is long-lived: a cached value would keep it
    // dispatching through a gateway ops turned off an hour ago.
    $settings = app(PayoutGatewaySettings::class);

    setGatewaySetting('payout.gateway', 'razorpay');
    expect($settings->isRazorpay())->toBeTrue();

    setGatewaySetting('payout.gateway', 'manual_neft');
    expect($settings->isRazorpay())->toBeFalse();
});

it('strips characters the banking rails reject from the narration', function (): void {
    setGatewaySetting('payout.razorpay.narration', 'Arovolife #Commission! — 2026');

    expect(app(PayoutGatewaySettings::class)->narration())
        ->toBe('Arovolife Commission  2026');
});

it('clamps the retry policy to a sane range', function (): void {
    setGatewaySetting('payout.razorpay.max_retries', '99');
    setGatewaySetting('payout.razorpay.auto_retry_hours', '0');
    $settings = app(PayoutGatewaySettings::class);

    expect($settings->maxRetries())->toBe(5)
        ->and($settings->autoRetryHours())->toBe(1);
});

// ── PII scrubbing ──────────────────────────────────────────────────────

it('never lets a bank account number, IFSC or name into the stored payload', function (): void {
    // The payouts API is the one place a request body carries the account
    // number. This is what keeps it out of payout_gateway_events and the log.
    $scrubbed = app(RazorpayPayoutPayloadScrubber::class)->scrub([
        'id' => 'fa_00000000000001',
        'entity' => 'fund_account',
        'contact_id' => 'cont_00000000000001',
        'account_type' => 'bank_account',
        'bank_account' => [
            'name' => 'Asha Kumari',
            'ifsc' => 'HDFC0001234',
            'bank_name' => 'HDFC Bank',
            'account_number' => '50100123456789',
        ],
        'name' => 'Asha Kumari',
        'email' => 'asha@example.com',
        'contact' => '9876543210',
        'debit_account_number' => '2323230012345678',
    ]);

    $encoded = json_encode($scrubbed);

    expect($encoded)->not->toContain('50100123456789')
        ->and($encoded)->not->toContain('HDFC0001234')
        ->and($encoded)->not->toContain('Asha Kumari')
        ->and($encoded)->not->toContain('asha@example.com')
        ->and($encoded)->not->toContain('9876543210')
        ->and($encoded)->not->toContain('2323230012345678')
        // What ops actually need is kept.
        ->and($scrubbed['id'])->toBe('fa_00000000000001')
        ->and($scrubbed['contact_id'])->toBe('cont_00000000000001');
});

it('keeps the transactional record a payout webhook carries', function (): void {
    $scrubbed = app(RazorpayPayoutPayloadScrubber::class)->scrub([
        'event' => 'payout.processed',
        'payload' => ['payout' => ['entity' => [
            'id' => 'pout_00000000000001',
            'status' => 'processed',
            'amount' => 150000,
            'currency' => 'INR',
            'mode' => 'NEFT',
            'utr' => 'HDFCN52026090412345',
            'reference_id' => 'AROVOPAY-42',
            'fund_account_id' => 'fa_00000000000001',
        ]]],
    ]);

    $entity = $scrubbed['payload']['payout']['entity'];

    expect($entity['utr'])->toBe('HDFCN52026090412345')
        ->and($entity['status'])->toBe('processed')
        ->and($entity['reference_id'])->toBe('AROVOPAY-42')
        ->and($entity['amount'])->toBe(150000);
});

// ── Idempotency ────────────────────────────────────────────────────────

it('derives the same idempotency key for the same line item and attempt', function (): void {
    // A replayed request after a timeout must resolve to the payout Razorpay
    // already made, not create a second transfer.
    $first = RazorpayPayoutGateway::idempotencyKey(42, 0);
    $again = RazorpayPayoutGateway::idempotencyKey(42, 0);
    $nextAttempt = RazorpayPayoutGateway::idempotencyKey(42, 1);
    $otherLine = RazorpayPayoutGateway::idempotencyKey(43, 0);

    expect($again)->toBe($first)
        ->and($nextAttempt)->not->toBe($first)
        ->and($otherLine)->not->toBe($first);
});

it('rejects a webhook whose signature does not verify', function (): void {
    config(['arovolife.payments.razorpay_payouts.webhook_secret' => 'whsec_test']);
    $gateway = app(RazorpayPayoutGateway::class);
    $body = '{"event":"payout.processed"}';

    expect($gateway->verifyWebhookSignature($body, hash_hmac('sha256', $body, 'whsec_test')))->toBeTrue()
        ->and($gateway->verifyWebhookSignature($body, 'deadbeef'))->toBeFalse()
        // No secret configured: nothing verifies, ever.
        ->and(tap(true, fn () => config(['arovolife.payments.razorpay_payouts.webhook_secret' => ''])))->toBeTrue()
        ->and($gateway->verifyWebhookSignature($body, hash_hmac('sha256', $body, 'whsec_test')))->toBeFalse();
});

// ── Manual NEFT reconciliation ─────────────────────────────────────────

/** A batch with one pending line item for a distributor with the given ADN. */
function reconcileFixture(string $adn): array
{
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_APPROVED,
    ]);

    $distributor = Distributor::factory()->create(['adn' => $adn]);

    $line = PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $distributor->id,
        'wallet_balance_paise' => 100_000,
        'gross_paise' => 100_000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 100_000,
        'status' => PayoutLineItem::STATUS_PENDING,
    ]);

    return [$batch, $line];
}

function uploadCsv(string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'neft').'.csv';
    file_put_contents($path, $contents);

    return new UploadedFile($path, 'bank-response.csv', 'text/csv', null, true);
}

it('settles a line item from the bank response file and records the UTR', function (): void {
    [$batch, $line] = reconcileFixture('ARV00001');

    $summary = app(PayoutReconciliationService::class)->import($batch, uploadCsv(
        "Line#,ADN,Bank Last4,Net Amount (INR),UTR,Status,Failure Reason\n".
        "1,ARV00001,4321,1000.00,HDFCN52026090412345,SUCCESS,\n"
    ), 1);

    expect($summary['transferred'])->toBe(1)
        ->and($line->fresh()->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED)
        ->and($line->fresh()->utr_number)->toBe('HDFCN52026090412345')
        // Nothing left pending, so the batch settles.
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_COMPLETED);
});

it('marks a bounced line failed with the bank’s own reason', function (): void {
    [$batch, $line] = reconcileFixture('ARV00002');

    $summary = app(PayoutReconciliationService::class)->import($batch, uploadCsv(
        "ADN,UTR,Status,Failure Reason\n".
        "ARV00002,,FAILED,Invalid IFSC code\n"
    ), 1);

    expect($summary['failed'])->toBe(1)
        ->and($line->fresh()->status)->toBe(PayoutLineItem::STATUS_FAILED)
        ->and($line->fresh()->failure_reason)->toBe('Invalid IFSC code')
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_FAILED);
});

it('re-importing the same response file changes nothing', function (): void {
    [$batch, $line] = reconcileFixture('ARV00003');
    $csv = "ADN,UTR,Status\nARV00003,HDFCN52026090400001,SUCCESS\n";

    $service = app(PayoutReconciliationService::class);
    $service->import($batch, uploadCsv($csv), 1);
    $second = $service->import($batch->fresh(), uploadCsv($csv), 1);

    expect($second['transferred'])->toBe(0)
        ->and($second['skipped'])->toHaveCount(1)
        ->and($line->fresh()->utr_number)->toBe('HDFCN52026090400001');
});

it('reports rows naming an ADN that is not in the batch instead of applying them', function (): void {
    [$batch, $line] = reconcileFixture('ARV00004');

    $summary = app(PayoutReconciliationService::class)->import($batch, uploadCsv(
        "ADN,Status\nARV99999,SUCCESS\n"
    ), 1);

    expect($summary['unmatched'])->toBe(['ARV99999'])
        ->and($summary['transferred'])->toBe(0)
        ->and($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING);
});

it('refuses a file with no ADN column rather than guessing', function (): void {
    [$batch] = reconcileFixture('ARV00005');

    $summary = app(PayoutReconciliationService::class)->import($batch, uploadCsv(
        "Name,Status\nAsha,SUCCESS\n"
    ), 1);

    expect($summary['errors'])->not->toBeEmpty()
        ->and($summary['transferred'])->toBe(0);
});

// ── Webhook settlement ─────────────────────────────────────────────────

/** A dispatched line item awaiting its payout webhook. */
function dispatchedFixture(string $payoutId): array
{
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_DISPATCHED,
    ]);

    $line = PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => Distributor::factory()->create()->id,
        'wallet_balance_paise' => 100_000,
        'gross_paise' => 100_000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 100_000,
        'status' => PayoutLineItem::STATUS_PENDING,
        'razorpay_payout_id' => $payoutId,
        'dispatched_at' => now(),
    ]);

    return [$batch, $line];
}

/** POST a signed payout webhook exactly as RazorpayX would. */
function postPayoutWebhook(array $body, ?string $eventId = null): TestResponse
{
    config(['arovolife.payments.razorpay_payouts.webhook_secret' => 'whsec_test']);
    $raw = json_encode($body, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/webhooks/razorpay/payouts', [], [], [], [
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $raw, 'whsec_test'),
        'HTTP_X_RAZORPAY_EVENT_ID' => $eventId ?? 'evt_'.bin2hex(random_bytes(6)),
        'CONTENT_TYPE' => 'application/json',
    ], $raw);
}

function payoutEvent(string $event, string $payoutId, array $entity = []): array
{
    return [
        'event' => $event,
        'payload' => ['payout' => ['entity' => array_merge([
            'id' => $payoutId,
            'status' => str_replace('payout.', '', $event),
            'amount' => 100_000,
            'currency' => 'INR',
        ], $entity)]],
    ];
}

it('settles a line item and its batch from a payout.processed webhook', function (): void {
    [$batch, $line] = dispatchedFixture('pout_test0000000001');

    postPayoutWebhook(payoutEvent('payout.processed', 'pout_test0000000001', [
        'utr' => 'HDFCN52026090499999',
    ]))->assertOk();

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED)
        ->and($line->fresh()->utr_number)->toBe('HDFCN52026090499999')
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_COMPLETED);
});

it('ignores a redelivered payout.processed instead of settling twice', function (): void {
    [, $line] = dispatchedFixture('pout_test0000000002');
    $body = payoutEvent('payout.processed', 'pout_test0000000002', ['utr' => 'UTR000000000001']);

    postPayoutWebhook($body, 'evt_duplicate')->assertOk();
    postPayoutWebhook($body, 'evt_duplicate')->assertOk()->assertJson(['status' => 'duplicate']);

    expect($line->fresh()->utr_number)->toBe('UTR000000000001');
});

it('never walks a settled transfer backwards on a late failure event', function (): void {
    // Razorpay re-orders deliveries. A stale payout.failed arriving after the
    // money landed must not un-pay a distributor.
    [, $line] = dispatchedFixture('pout_test0000000003');

    postPayoutWebhook(payoutEvent('payout.processed', 'pout_test0000000003', ['utr' => 'UTR000000000002']))->assertOk();
    postPayoutWebhook(payoutEvent('payout.failed', 'pout_test0000000003'))->assertOk();

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED);
});

it('lets a genuine reversal overturn a settled transfer', function (): void {
    [, $line] = dispatchedFixture('pout_test0000000004');

    postPayoutWebhook(payoutEvent('payout.processed', 'pout_test0000000004', ['utr' => 'UTR000000000003']))->assertOk();
    postPayoutWebhook(payoutEvent('payout.reversed', 'pout_test0000000004'))->assertOk();

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_FAILED)
        ->and($line->fresh()->failure_reason)->toContain('reversed');
});

it('stores nothing and changes nothing when the webhook signature is wrong', function (): void {
    [, $line] = dispatchedFixture('pout_test0000000005');
    config(['arovolife.payments.razorpay_payouts.webhook_secret' => 'whsec_test']);
    $raw = json_encode(payoutEvent('payout.processed', 'pout_test0000000005'), JSON_THROW_ON_ERROR);

    // 200 on purpose: a 4xx makes Razorpay redeliver the same bad body all day.
    $this->call('POST', '/webhooks/razorpay/payouts', [], [], [], [
        'HTTP_X_RAZORPAY_SIGNATURE' => 'not-a-real-signature',
        'CONTENT_TYPE' => 'application/json',
    ], $raw)->assertOk()->assertJson(['status' => 'ignored']);

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and(DB::table('payout_gateway_events')->count())->toBe(0);
});

it('leaves interim payout events as evidence without touching the line item', function (): void {
    [, $line] = dispatchedFixture('pout_test0000000006');

    postPayoutWebhook(payoutEvent('payout.initiated', 'pout_test0000000006'))->assertOk();

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and(DB::table('payout_gateway_events')->count())->toBe(1);
});
