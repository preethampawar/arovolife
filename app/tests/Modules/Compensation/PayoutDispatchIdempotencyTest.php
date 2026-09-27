<?php

declare(strict_types=1);

/**
 * A payout line may reach Razorpay only once per live transfer.
 *
 * PD-01: a retry that finds a live payout by reference adopts it and sends nothing
 * PD-02: a retry that finds only a dead (reversed) payout creates a new one
 * PD-03: a first dispatch with nothing at Razorpay posts once, keyed on attempt 0
 * PD-04: a lookup that fails holds the line and sends nothing
 * PD-05: the retry job outlives the worst-case gateway time
 * PD-06: a live payout listed after a dead one is still adopted
 * PD-07: two live payouts under one reference hold the line, nothing sent
 * PD-08: a live payout whose amount differs from the line is never adopted
 * PD-09: a line in an NEFT bank file for this attempt is never sent to Razorpay
 */

use App\Modules\Compensation\Jobs\RetryRazorpayPayoutJob;
use App\Modules\Compensation\Models\PayoutBankFileRow;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\RazorpayPayoutDispatchService;
use App\Modules\Compensation\Services\RazorpayPayoutGateway;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

/**
 * A pending line whose distributor already has a Razorpay contact and bank
 * details matching the fund account the fake returns.
 */
function pdiLine(string $adn, string $status = PayoutLineItem::STATUS_PENDING, int $retryCount = 0): PayoutLineItem
{
    [, $line] = reconcileFixture($adn);

    Distributor::whereKey($line->distributor_id)->update([
        'razorpay_contact_id' => 'cont_'.$adn,
        'bank_ifsc' => 'HDFC0000001',
        'bank_account_enc' => PiiCrypter::encryptString('50100012345678'),
    ]);

    $line->forceFill(['status' => $status, 'retry_count' => $retryCount])->save();

    return $line->fresh();
}

/**
 * Fake RazorpayX: the fund-account list matches the line's bank details, the
 * reference lookup answers with $found, and a create answers with a new payout.
 *
 * @param  list<array<string, mixed>>  $found  the payouts the reference lookup returns, in order
 */
function pdiFakeRazorpay(array $found, int $lookupStatus = 200): void
{
    Http::fake(function (Request $request) use ($found, $lookupStatus) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/fund_accounts')) {
            return Http::response(['items' => [[
                'id' => 'fa_1', 'account_type' => 'bank_account', 'active' => true,
                'bank_account' => ['ifsc' => 'HDFC0000001', 'account_number' => '50100012345678'],
            ]]]);
        }

        if (str_ends_with($path, '/payouts') && $request->method() === 'GET') {
            return $lookupStatus === 200
                ? Http::response(['items' => $found])
                : Http::response(['error' => ['code' => 'SERVER_ERROR', 'description' => 'upstream']], $lookupStatus);
        }

        if (str_ends_with($path, '/payouts') && $request->method() === 'POST') {
            return Http::response(['id' => 'pout_new', 'status' => 'queued']);
        }

        return Http::response([], 404);
    });
}

/** @return array<string, mixed> a payout as Razorpay lists it under this line's reference */
function pdiPayout(PayoutLineItem $line, string $id, string $status, ?int $amount = null): array
{
    return [
        'id' => $id, 'status' => $status, 'reference_id' => 'AROVOPAY-'.$line->id,
        'amount' => $amount ?? (int) $line->net_transferred_paise, 'fund_account_id' => 'fa_1', 'mode' => 'NEFT',
    ];
}

function pdiDispatch(PayoutLineItem $line): bool
{
    return app(RazorpayPayoutDispatchService::class)->dispatch($line, null, RazorpayPayoutDispatchService::AUDIT_DISPATCHED);
}

function pdiHoldCause(PayoutLineItem $line): ?string
{
    return AuditLog::where('action', 'payout.line_item.dispatch_failed')->where('subject_id', $line->id)->latest('id')->first()?->details['cause'];
}

function pdiCreates(): int
{
    return Http::recorded(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/payouts'))->count();
}

it('PD-01: a retry that finds a live payout by reference adopts it and sends nothing', function (): void {
    $line = pdiLine('ADN9101', PayoutLineItem::STATUS_FAILED, 1);
    pdiFakeRazorpay([pdiPayout($line, 'pout_live', 'processing')]);

    (new RetryRazorpayPayoutJob((int) $line->id))->handle(
        app(RazorpayPayoutDispatchService::class),
        app(PayoutGatewaySettings::class),
    );

    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
        && str_contains($r->url(), 'reference_id=AROVOPAY-'.$line->id));

    $audit = AuditLog::where('action', RazorpayPayoutDispatchService::AUDIT_RETRY_DISPATCHED)->where('subject_id', $line->id)->sole();

    expect(pdiCreates())->toBe(0)
        ->and($line->fresh()->razorpay_payout_id)->toBe('pout_live')
        ->and($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and($audit->details['adopted_existing'])->toBeTrue();
});

it('PD-02: a retry whose reference lookup finds only a reversed payout creates a new one', function (): void {
    $line = pdiLine('ADN9102', PayoutLineItem::STATUS_FAILED, 1);
    pdiFakeRazorpay([pdiPayout($line, 'pout_dead', 'reversed')]);

    (new RetryRazorpayPayoutJob((int) $line->id))->handle(
        app(RazorpayPayoutDispatchService::class),
        app(PayoutGatewaySettings::class),
    );

    $audit = AuditLog::where('action', RazorpayPayoutDispatchService::AUDIT_RETRY_DISPATCHED)->where('subject_id', $line->id)->sole();

    expect(pdiCreates())->toBe(1)
        ->and($line->fresh()->razorpay_payout_id)->toBe('pout_new')
        ->and($audit->details['adopted_existing'])->toBeFalse();
});

it('PD-03: a first dispatch with nothing at Razorpay posts once, keyed on attempt 0', function (): void {
    $line = pdiLine('ADN9103');
    pdiFakeRazorpay([]);

    $sent = app(RazorpayPayoutDispatchService::class)->dispatch($line, null, RazorpayPayoutDispatchService::AUDIT_DISPATCHED);

    expect($sent)->toBeTrue()
        ->and(pdiCreates())->toBe(1)
        ->and($line->fresh()->razorpay_payout_id)->toBe('pout_new');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r->hasHeader('X-Payout-Idempotency', RazorpayPayoutGateway::idempotencyKey((int) $line->id, 0)));
});

it('PD-04: a reference lookup that fails holds the line and sends nothing', function (): void {
    $line = pdiLine('ADN9104');
    pdiFakeRazorpay([], 502);

    $sent = app(RazorpayPayoutDispatchService::class)->dispatch($line, null, RazorpayPayoutDispatchService::AUDIT_DISPATCHED);

    $audit = AuditLog::where('action', 'payout.line_item.dispatch_failed')->where('subject_id', $line->id)->sole();

    expect($sent)->toBeFalse()
        ->and(pdiCreates())->toBe(0)
        ->and($line->fresh()->status)->toBe(PayoutLineItem::STATUS_FAILED)
        ->and($line->fresh()->razorpay_payout_id)->toBeNull()
        ->and($audit->details['cause'])->toBe('gateway_lookup_failed');
});

it('PD-05: the retry job outlives the worst-case gateway time', function (): void {
    expect((new RetryRazorpayPayoutJob(1))->timeout)->toBeGreaterThanOrEqual(300);
});

it('PD-06: a live payout listed after a dead one is still adopted', function (): void {
    $line = pdiLine('ADN9106', PayoutLineItem::STATUS_FAILED, 1);
    pdiFakeRazorpay([pdiPayout($line, 'pout_dead', 'rejected'), pdiPayout($line, 'pout_live', 'queued')]);

    pdiDispatch($line);

    expect(pdiCreates())->toBe(0)
        ->and($line->fresh()->razorpay_payout_id)->toBe('pout_live')
        ->and($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING);
});

it('PD-07: two live payouts under one reference hold the line and send nothing', function (): void {
    $line = pdiLine('ADN9107');
    pdiFakeRazorpay([pdiPayout($line, 'pout_a', 'processing'), pdiPayout($line, 'pout_b', 'processed')]);

    expect(pdiDispatch($line))->toBeFalse()
        ->and(pdiCreates())->toBe(0)
        ->and($line->fresh()->status)->toBe(PayoutLineItem::STATUS_FAILED)
        ->and($line->fresh()->razorpay_payout_id)->toBeNull()
        ->and(pdiHoldCause($line))->toBe('gateway_multiple_live');
});

it('PD-08: a live payout whose amount differs from the line is never adopted', function (): void {
    $line = pdiLine('ADN9108');
    pdiFakeRazorpay([pdiPayout($line, 'pout_other', 'processing', 1)]);

    expect(pdiDispatch($line))->toBeFalse()
        ->and(pdiCreates())->toBe(0)
        ->and($line->fresh()->razorpay_payout_id)->toBeNull()
        ->and(pdiHoldCause($line))->toBe('gateway_lookup_mismatch');
});

it('PD-09: a line in an NEFT bank file for this attempt is never sent to Razorpay', function (): void {
    $line = pdiLine('ADN9109');
    PayoutBankFileRow::create([
        'payout_bank_file_id' => 1, 'row_no' => 1, 'adn' => 'ADN9109', 'payout_line_item_id' => $line->id,
        'attempt' => 0, 'amount_paise' => $line->net_transferred_paise, 'result' => PayoutBankFileRow::RESULT_SENT,
    ]);
    Http::fake();

    expect(pdiDispatch($line))->toBeFalse();

    Http::assertNothingSent();
    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and(AuditLog::where('action', 'payout.line_item.dispatch_skipped')->where('subject_id', $line->id)->sole()->details['cause'])->toBe('in_bank_file');
});
