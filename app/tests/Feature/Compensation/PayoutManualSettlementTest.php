<?php

declare(strict_types=1);

/**
 * The manual controls that finish a payout line: mark paid, mark failed, mark
 * returned, send again, check with Razorpay — and the unpaid-only bank file
 * that makes "send again" work in Manual NEFT mode.
 *
 * The money left the wallet when the batch was built; every case here checks
 * that these controls only ever record what the bank did or put the same line
 * back in front of it, and never touch the wallet.
 */

use App\Modules\Compensation\Exceptions\PayoutLineActionRefused;
use App\Modules\Compensation\Jobs\RetryRazorpayPayoutJob;
use App\Modules\Compensation\Models\PayoutBankFile;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutLineSettlementService;
use App\Modules\Compensation\Services\PayoutReconciliationService;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\RazorpayPayoutDispatchService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(PayoutBankFile::DISK);
    setGatewaySetting('payout.gateway', 'manual_neft');
});

function financeUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin-finance');

    return $user;
}

/** A second pending line on an existing batch. */
function extraLine(PayoutBatch $batch, string $adn, int $netPaise = 50_000, string $status = PayoutLineItem::STATUS_PENDING): PayoutLineItem
{
    return PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => Distributor::factory()->create(['adn' => $adn])->id,
        'wallet_balance_paise' => $netPaise,
        'gross_paise' => $netPaise,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => $netPaise,
        'status' => $status,
    ]);
}

function settlement(): PayoutLineSettlementService
{
    return app(PayoutLineSettlementService::class);
}

/** @return array{count: int, sum: int} */
function walletLedgerFingerprint(): array
{
    return [
        'count' => (int) DB::table('wallet_ledger_entries')->count(),
        'sum' => (int) DB::table('wallet_ledger_entries')->sum('amount_paise'),
    ];
}

function useRazorpay(): void
{
    setGatewaySetting('payout.gateway', 'razorpay');
    config([
        'arovolife.payments.razorpay_payouts.key_id' => 'rzp_test_key',
        'arovolife.payments.razorpay_payouts.key_secret' => 'rzp_test_secret',
        'arovolife.payments.razorpay_payouts.account_number' => '2323230000000000',
    ]);
}

function fakeRazorpayPayout(string $payoutId, string $status, ?string $utr = null): void
{
    Http::fake([
        '*/payouts/'.$payoutId => Http::response(['id' => $payoutId, 'status' => $status, 'utr' => $utr]),
    ]);
}

// ── Mark paid / mark failed ────────────────────────────────────────────

it('marks a waiting line paid with its UTR and completes the batch with its last line', function (): void {
    [$batch, $line] = reconcileFixture('ADN2001');

    settlement()->markLinePaid($line, 'utr0001abc', financeUser()->id);

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED)
        ->and($line->fresh()->utr_number)->toBe('UTR0001ABC')
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_COMPLETED)
        ->and(DB::table('audit_log')->where('action', 'payout.line_item.marked_paid')->where('subject_id', $line->id)->exists())->toBeTrue();
});

it('refuses a UTR already settling another line', function (): void {
    [$batch, $line] = reconcileFixture('ADN2002');
    $other = extraLine($batch, 'ADN2003', status: PayoutLineItem::STATUS_TRANSFERRED);
    $other->forceFill(['utr_number' => 'UTRTAKEN01'])->save();

    expect(fn () => settlement()->markLinePaid($line, 'utrtaken01', financeUser()->id))
        ->toThrow(PayoutLineActionRefused::class);

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING);
});

it('never lets a person mark a Razorpay line paid or failed — the dispatch job may still send it', function (): void {
    useRazorpay();
    [$batch, $line] = reconcileFixture('ADN2004');

    expect(fn () => settlement()->markLinePaid($line, 'UTR2004', financeUser()->id))->toThrow(PayoutLineActionRefused::class)
        ->and(fn () => settlement()->markFailed($line, 'bounced', financeUser()->id))->toThrow(PayoutLineActionRefused::class);

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING);
});

it('marks a failed line paid when the bank confirms it went through after all', function (): void {
    [$batch, $line] = reconcileFixture('ADN2005');
    $line->forceFill(['status' => PayoutLineItem::STATUS_FAILED, 'failure_reason' => 'Timeout'])->save();

    settlement()->markLinePaid($line, 'UTR2005', financeUser()->id);

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED)
        ->and($line->fresh()->failure_reason)->toBeNull();
});

it('marks a waiting line failed with a reason and the batch partially failed', function (): void {
    [$batch, $line] = reconcileFixture('ADN2006');
    $paid = extraLine($batch, 'ADN2007');
    settlement()->markLinePaid($paid, 'UTR2007', financeUser()->id);

    expect(fn () => settlement()->markFailed($line, '   ', financeUser()->id))->toThrow(PayoutLineActionRefused::class);

    settlement()->markFailed($line, 'Account closed', financeUser()->id);

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_FAILED)
        ->and($line->fresh()->failure_reason)->toBe('Account closed')
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_PARTIALLY_FAILED);
});

// ── Send again (Manual NEFT) ───────────────────────────────────────────

it('sends a failed line again: back to waiting, attempt counted, batch awaiting the bank again', function (): void {
    [$batch, $line] = reconcileFixture('ADN2008');
    settlement()->markFailed($line, 'Invalid IFSC', financeUser()->id);
    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_FAILED);

    $line->forceFill(['dispatched_at' => now()->subDay()])->save();
    $dispatchedAt = $line->fresh()->dispatched_at;

    settlement()->sendAgain($line->fresh(), financeUser()->id);

    $fresh = $line->fresh();
    expect($fresh->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and($fresh->retry_count)->toBe(1)
        ->and($fresh->failure_reason)->toBeNull()
        // Not ours in manual mode — the TDS register reads it.
        ->and($fresh->dispatched_at?->toDateTimeString())->toBe($dispatchedAt?->toDateTimeString())
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_APPROVED);

    $audit = DB::table('audit_log')->where('action', 'payout.line_item.sent_again')->where('subject_id', $line->id)->first();
    expect(json_decode((string) $audit->details, true)['previous_failure_reason'])->toBe('Invalid IFSC');
});

it('runs the whole manual loop: bounce, send again, re-export only that line, settle', function (): void {
    [$batch, $line] = reconcileFixture('ADN2009');
    $other = extraLine($batch, 'ADN2010', 50_000);
    $finance = financeUser();
    $before = walletLedgerFingerprint();

    // First bank file carries both lines.
    $first = $this->actingAs($finance)->get(route('admin.compensation.weekly-payouts.neft', $batch))->assertOk()->streamedContent();
    expect($first)->toContain('ADN2009')->toContain('ADN2010');

    // The bank pays one and bounces the other.
    app(PayoutReconciliationService::class)->import($batch, uploadCsv("ADN,Status,UTR,Reason\nADN2010,Success,UTR2010,\nADN2009,Failed,,Invalid account\n"), $finance->id);
    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_FAILED);

    $this->actingAs($finance)->post(route('admin.compensation.weekly-payouts.line-items.retry', [$batch, $line]))
        ->assertRedirect(route('admin.compensation.weekly-payouts.show', $batch))
        ->assertSessionHas('success');

    // The second file holds only the unpaid line, and does not count the
    // bounced file as an earlier copy of it.
    $second = $this->actingAs($finance)->get(route('admin.compensation.weekly-payouts.neft', $batch))->assertOk()->streamedContent();
    expect($second)->toContain('ADN2009')->not->toContain('ADN2010');

    $export = PayoutBankFile::where('direction', 'export')->orderByDesc('id')->first();
    expect($export->summary['line_count'])->toBe(1)
        ->and($export->summary['already_in_earlier_file'])->toBe(0);

    app(PayoutReconciliationService::class)->import($batch, uploadCsv("ADN,Status,UTR\nADN2009,Success,UTR2009B\n"), $finance->id);

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED)
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_COMPLETED)
        ->and(walletLedgerFingerprint())->toBe($before);
});

it('warns when a waiting line is already in a bank file downloaded since it became payable', function (): void {
    [$batch, $line] = reconcileFixture('ADN2011');
    $finance = financeUser();

    $this->actingAs($finance)->get(route('admin.compensation.weekly-payouts.neft', $batch))->assertOk();
    $this->actingAs($finance)->get(route('admin.compensation.weekly-payouts.neft', $batch))->assertOk();

    $latest = PayoutBankFile::where('direction', 'export')->orderByDesc('id')->first();
    expect($latest->summary['already_in_earlier_file'])->toBe(1);
});

it('leaves paid lines out of the bank file and refuses a file with nothing to pay', function (): void {
    [$batch, $line] = reconcileFixture('ADN2012');
    $finance = financeUser();
    settlement()->markLinePaid($line, 'UTR2012', $finance->id);

    $this->actingAs($finance)
        ->from(route('admin.compensation.weekly-payouts.show', $batch))
        ->get(route('admin.compensation.weekly-payouts.neft', $batch))
        ->assertRedirect(route('admin.compensation.weekly-payouts.show', $batch))
        ->assertSessionHas('error');

    expect(PayoutBankFile::count())->toBe(0);
});

// ── Mark returned ──────────────────────────────────────────────────────

it('marks a paid line returned: failed again, UTR kept only in the audit, completed batch reopened', function (): void {
    [$batch, $line] = reconcileFixture('ADN2013');
    $paid = extraLine($batch, 'ADN2014');
    settlement()->markLinePaid($line, 'UTR2013', financeUser()->id);
    settlement()->markLinePaid($paid, 'UTR2014', financeUser()->id);
    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_COMPLETED);

    settlement()->markReturned($line->fresh(), 'Account closed', financeUser()->id);

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_FAILED)
        ->and($line->fresh()->utr_number)->toBeNull()
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_PARTIALLY_FAILED);

    $audit = DB::table('audit_log')->where('action', 'payout.line_item.marked_returned')->first();
    expect(json_decode((string) $audit->details, true)['previous_utr'])->toBe('UTR2013');
});

it('refuses mark returned in Razorpay mode — Razorpay reports its own reversals', function (): void {
    [$batch, $line] = reconcileFixture('ADN2015');
    settlement()->markLinePaid($line, 'UTR2015', financeUser()->id);
    useRazorpay();

    expect(fn () => settlement()->markReturned($line->fresh(), 'Returned', financeUser()->id))
        ->toThrow(PayoutLineActionRefused::class);
});

// ── Razorpay ───────────────────────────────────────────────────────────

it('refuses to send a Razorpay line again while Razorpay says the old payout went through', function (): void {
    useRazorpay();
    Queue::fake();
    [$batch, $line] = dispatchedFixture('pout_alive000000001');
    $line->forceFill(['status' => PayoutLineItem::STATUS_FAILED])->save();
    fakeRazorpayPayout('pout_alive000000001', 'processed', 'UTRALIVE');

    expect(fn () => settlement()->sendAgain($line->fresh(), financeUser()->id))->toThrow(PayoutLineActionRefused::class);

    expect($line->fresh()->razorpay_payout_id)->toBe('pout_alive000000001');
    Queue::assertNothingPushed();
});

it('sends a dead Razorpay payout again past the retry limit, but the bulk button keeps the limit', function (): void {
    useRazorpay();
    Queue::fake();
    [$batch, $line] = dispatchedFixture('pout_dead0000000001');
    $line->forceFill(['status' => PayoutLineItem::STATUS_FAILED, 'retry_count' => 9])->save();
    fakeRazorpayPayout('pout_dead0000000001', 'reversed');

    expect(fn () => settlement()->sendAgain($line->fresh(), financeUser()->id, respectRetryLimit: true))
        ->toThrow(PayoutLineActionRefused::class);

    settlement()->sendAgain($line->fresh(), financeUser()->id);

    expect($line->fresh()->razorpay_payout_id)->toBeNull()
        ->and($line->fresh()->status)->toBe(PayoutLineItem::STATUS_FAILED);

    Queue::assertPushed(RetryRazorpayPayoutJob::class, 1);
});

it('checks a waiting transfer with Razorpay and applies what Razorpay reports', function (string $state, string $expectedStatus): void {
    useRazorpay();
    [$batch, $line] = dispatchedFixture('pout_check00000001');
    fakeRazorpayPayout('pout_check00000001', $state, $state === 'processed' ? 'UTRCHECK1' : null);

    settlement()->checkWithRazorpay($line, financeUser()->id);

    expect($line->fresh()->status)->toBe($expectedStatus);
    if ($state === 'processed') {
        expect($line->fresh()->utr_number)->toBe('UTRCHECK1');
    }
    expect(DB::table('audit_log')->where('action', 'payout.line_item.gateway_checked')->exists())->toBeTrue();
})->with([
    'processed' => ['processed', PayoutLineItem::STATUS_TRANSFERRED],
    'reversed' => ['reversed', PayoutLineItem::STATUS_FAILED],
    'still queued' => ['queued', PayoutLineItem::STATUS_PENDING],
]);

it('never dispatches a line settled after the batch job loaded it', function (): void {
    useRazorpay();
    Http::fake();
    [$batch, $line] = reconcileFixture('ADN2016');
    $stale = $line->fresh();

    // Settled behind the job's back.
    $line->forceFill(['status' => PayoutLineItem::STATUS_TRANSFERRED, 'utr_number' => 'UTR2016'])->save();

    app(RazorpayPayoutDispatchService::class)->dispatch($stale, null, RazorpayPayoutDispatchService::AUDIT_DISPATCHED);

    Http::assertNothingSent();
    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED);
});

// ── Approval, routing, permissions ─────────────────────────────────────

it('refuses every control, the bank file and the import on a batch nobody approved', function (): void {
    [$batch, $line] = reconcileFixture('ADN2017');
    $batch->forceFill(['status' => PayoutBatch::STATUS_PARTIALLY_FAILED, 'approved_at' => null])->save();
    $finance = financeUser();
    $base = 'admin.compensation.weekly-payouts.';

    foreach ([
        ['line-items.mark-paid', ['utr' => 'UTR2017']],
        ['line-items.mark-failed', ['reason' => 'x']],
        ['line-items.retry', []],
    ] as [$name, $payload]) {
        $this->actingAs($finance)->post(route($base.$name, [$batch, $line]), $payload)->assertSessionHas('error');
    }

    $this->actingAs($finance)->get(route($base.'neft', $batch))->assertSessionHas('error');
    $this->actingAs($finance)->post(route($base.'reconcile', $batch), [
        'response_file' => uploadCsv("ADN,Status\nADN2017,Success\n"),
    ])->assertSessionHas('error');

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and($batch->fresh()->status)->toBe(PayoutBatch::STATUS_PARTIALLY_FAILED)
        ->and(PayoutBankFile::count())->toBe(0);
});

it('404s a line from another batch and 403s a role without finance.record', function (string $section): void {
    [$batch, $line] = reconcileFixture('ADN2018');
    [$otherBatch] = reconcileFixture('ADN2019', 3);
    $base = "admin.compensation.{$section}-payouts.";

    $this->actingAs(financeUser())
        ->post(route($base.'line-items.mark-paid', [$otherBatch, $line]), ['utr' => 'UTR2018'])
        ->assertNotFound();

    $compliance = User::factory()->create(['status' => 'active']);
    $compliance->assignRole('admin-compliance');

    foreach (['mark-paid', 'mark-failed', 'mark-returned', 'check-gateway', 'retry'] as $action) {
        $this->actingAs($compliance)->post(route($base.'line-items.'.$action, [$batch, $line]))->assertForbidden();
    }

    expect($line->fresh()->status)->toBe(PayoutLineItem::STATUS_PENDING);
})->with(['weekly', 'monthly']);

it('never touches the wallet ledger, whatever the controls do', function (): void {
    [$batch, $line] = reconcileFixture('ADN2020');
    $finance = financeUser();
    $before = walletLedgerFingerprint();

    settlement()->markFailed($line, 'Bounced', $finance->id);
    settlement()->sendAgain($line->fresh(), $finance->id);
    settlement()->markLinePaid($line->fresh(), 'UTR2020', $finance->id);
    settlement()->markReturned($line->fresh(), 'Returned', $finance->id);
    settlement()->sendAgain($line->fresh(), $finance->id);

    expect(walletLedgerFingerprint())->toBe($before);
});

it('never appends to a signed-off batch the bank has partly settled when the batch date is re-run', function (): void {
    [$batch, $line] = reconcileFixture('ADN2021');
    settlement()->markFailed($line, 'Bounced', financeUser()->id);
    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_FAILED);

    $linesBefore = $batch->lineItems()->count();

    app(PayoutService::class)->runWeeklyBatch(now()->startOfDay());

    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_FAILED)
        ->and($batch->lineItems()->count())->toBe($linesBefore);
});

it('leaves lines Razorpay itself failed out of the bulk resend, and names them', function (): void {
    useRazorpay();
    Queue::fake();
    Http::fake();
    [$batch, $dead] = dispatchedFixture('pout_bulk000000001');
    $dead->forceFill(['status' => PayoutLineItem::STATUS_FAILED])->save();
    $neverSent = extraLine($batch, 'ADN2022', status: PayoutLineItem::STATUS_FAILED);

    $this->actingAs(financeUser())
        ->post(route('admin.compensation.weekly-payouts.retry-failed', $batch))
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'use Send again on the line'));

    // No Razorpay call from the web request; only the never-sent line is queued.
    Http::assertNothingSent();
    Queue::assertPushed(RetryRazorpayPayoutJob::class, 1);
    expect($dead->fresh()->razorpay_payout_id)->toBe('pout_bulk000000001');
});
