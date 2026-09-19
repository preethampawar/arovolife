<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Exceptions\BatchIsFrozen;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutGatewayEvent;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Carbon::setTestNow(Carbon::parse('2026-09-22 03:00:00', 'Asia/Kolkata'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A distributor over the 3,000 BV Retailer gate, so the sweep pays them. */
function unbuildEligibleDistributor(): Distributor
{
    $distributor = Distributor::factory()->create();

    BvLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'order_id' => 800_000 + $distributor->id,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    return $distributor;
}

/**
 * A pending weekly batch with one paid distributor, built the way the weekly
 * run builds it.
 *
 * @return array{0: PayoutBatch, 1: Distributor}
 */
function pendingWeeklyBatch(?Carbon $batchDate = null): array
{
    $batchDate ??= Carbon::parse('2026-09-22');
    $distributor = unbuildEligibleDistributor();

    app(WalletService::class)->creditWithRepurchaseDeduction(
        distributorId: $distributor->id,
        grossPaise: 100_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: $batchDate->copy()->startOfMonth(),
        earnedOn: PayoutBatch::weeklyEarningWindow($batchDate)['end'],
    );

    return [app(PayoutService::class)->runWeeklyBatch($batchDate), $distributor];
}

it('un-sweeps the credits, deletes the batch\'s own debits, its lines and its row', function (): void {
    [$batch, $distributor] = pendingWeeklyBatch();

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->firstOrFail();

    // A forfeit is written against the line item with the earned month in its
    // reference_type; it belongs to the batch exactly as the three debits do.
    app(WalletService::class)->debit(
        distributorId: $distributor->id,
        amountPaise: 2_500,
        type: 'income_cap_forfeit',
        referenceId: $line->id,
        referenceType: 'payout_line_item_2026-09-01',
        memo: 'Cash income above the combined monthly income cap',
    );

    $creditIds = WalletLedgerEntry::whereIn('type', ['gsb_credit', 'repurchase_transfer'])->pluck('id');

    expect(WalletLedgerEntry::whereIn('id', $creditIds)->whereNotNull('swept_by_payout_batch_id')->count())
        ->toBe($creditIds->count());

    $summary = app(PayoutService::class)->unbuildBatch($batch, 42, 'Rebuilding the week');

    expect($summary['entries_unswept'])->toBe($creditIds->count());
    expect($summary['line_items'])->toBe(1);
    expect($summary['debits_deleted'])->toBe(3);
    expect($summary['forfeits_deleted'])->toBe(1);
    expect($summary['forfeits_paise'])->toBe(-2_500);

    // Credits survive untouched, with the batch's stamp removed.
    expect(WalletLedgerEntry::whereIn('id', $creditIds)->count())->toBe($creditIds->count());
    expect(WalletLedgerEntry::whereIn('id', $creditIds)->whereNotNull('swept_by_payout_batch_id')->count())->toBe(0);

    // The batch's own projection is gone.
    expect(WalletLedgerEntry::whereIn('type', ['payout_debit', 'admin_charge_debit', 'tds_debit', 'income_cap_forfeit'])->count())->toBe(0);
    expect(PayoutLineItem::count())->toBe(0);
    expect(PayoutBatch::find($batch->id))->toBeNull();
});

it('audits the un-build with the batch, the ids and the paise sums', function (): void {
    [$batch] = pendingWeeklyBatch();

    app(PayoutService::class)->unbuildBatch($batch, 42, 'Rebuilding the week');

    $audit = AuditLog::where('action', 'payout.batch.unbuilt')->firstOrFail();

    expect($audit->actor_id)->toBe(42);
    expect($audit->subject_type)->toBe('payout_batch');
    expect((int) $audit->subject_id)->toBe($batch->id);
    expect($audit->before_hash)->not->toBeNull();
    expect($audit->details['reason'])->toBe('Rebuilding the week');
    expect($audit->details['batch_type'])->toBe(PayoutBatch::TYPE_WEEKLY);
    expect($audit->details['debit_ids'])->toHaveCount(3);
    expect($audit->details['debits_paise'])->toBeLessThan(0);
    expect($audit->details['line_item_ids'])->toHaveCount(1);
});

it('refuses a batch finance has signed off', function (string $status, bool $approvedAt): void {
    [$batch] = pendingWeeklyBatch();

    $batch->update([
        'status' => $status,
        'approved_at' => $approvedAt ? now() : null,
    ]);

    expect(fn () => app(PayoutService::class)->unbuildBatch($batch, 42, 'Rebuilding the week'))
        ->toThrow(BatchIsFrozen::class);

    expect(PayoutBatch::find($batch->id))->not->toBeNull();
    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->count())->toBe(1);
})->with([
    'approved' => [PayoutBatch::STATUS_APPROVED, false],
    'dispatched' => [PayoutBatch::STATUS_DISPATCHED, false],
    'completed' => [PayoutBatch::STATUS_COMPLETED, false],
    'processing' => [PayoutBatch::STATUS_PROCESSING, false],
    // The bank rejected it AFTER finance approved: the instruction still left
    // the company, so the remedy is a per-line retry (DN-2).
    'bank-rejected but approved' => [PayoutBatch::STATUS_FAILED, true],
]);

it('refuses a batch that reached the payment gateway', function (): void {
    [$batch] = pendingWeeklyBatch();

    PayoutGatewayEvent::create([
        'payout_batch_id' => $batch->id,
        'payout_line_item_id' => PayoutLineItem::where('payout_batch_id', $batch->id)->value('id'),
        'gateway' => 'razorpay',
        'direction' => 'outbound',
        'event_type' => 'payout.create',
        'signature_verified' => false,
    ]);

    expect(fn () => app(PayoutService::class)->unbuildBatch($batch, 42, 'Rebuilding the week'))
        ->toThrow(BatchIsFrozen::class, 'reached Razorpay');

    expect(PayoutBatch::find($batch->id))->not->toBeNull();
});

it('removes a held line, which carries no debits of its own', function (): void {
    $batchDate = Carbon::parse('2026-09-22');
    [$batch] = pendingWeeklyBatch($batchDate);

    $held = unbuildEligibleDistributor();
    $held->update(['bank_account_enc' => null]);
    $held->user->update(['status' => 'pending']);

    app(WalletService::class)->credit(
        distributorId: $held->id,
        amountPaise: 100_000,
        type: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        earnedOn: PayoutBatch::weeklyEarningWindow($batchDate)['end'],
    );

    // The next Tuesday's batch reaches the held distributor: a line is written
    // for them, but no money moves, so none of the three debits exists.
    $next = app(PayoutService::class)->runWeeklyBatch($batchDate->copy()->addWeek());

    $heldLine = PayoutLineItem::where('payout_batch_id', $next->id)->where('distributor_id', $held->id)->firstOrFail();

    expect(WalletLedgerEntry::where('reference_type', 'payout_line_item')->where('reference_id', $heldLine->id)->count())->toBe(0);

    $summary = app(PayoutService::class)->unbuildBatch($next, 42, 'Rebuilding the week');

    expect($summary['line_items'])->toBe(1);
    expect($summary['debits_deleted'])->toBe(0);
    expect(PayoutLineItem::where('payout_batch_id', $next->id)->count())->toBe(0);
    expect(PayoutBatch::find($next->id))->toBeNull();
    // The earlier batch is untouched.
    expect(PayoutBatch::find($batch->id))->not->toBeNull();
});

it('holds the sweep lock for the whole un-build', function (): void {
    [$batch] = pendingWeeklyBatch();

    $heldDuringUnbuild = [];

    Event::listen('eloquent.deleting: '.PayoutBatch::class, function () use (&$heldDuringUnbuild): void {
        $heldDuringUnbuild[] = ! Cache::lock('compensation:payout-sweep', 5)->get();
    });

    app(PayoutService::class)->unbuildBatch($batch, 42, 'Rebuilding the week');

    expect($heldDuringUnbuild)->toBe([true]);
});

it('rebuilds the same line items, with the developer as the batch maker', function (): void {
    $batchDate = Carbon::parse('2026-09-22');
    [$batch, $distributor] = pendingWeeklyBatch($batchDate);

    $before = PayoutLineItem::where('payout_batch_id', $batch->id)
        ->get(['distributor_id', 'gross_paise', 'admin_charge_paise', 'tds_paise', 'net_transferred_paise'])
        ->toArray();

    app(PayoutService::class)->unbuildBatch($batch, 42, 'Rebuilding the week');

    app(EngineRunContext::class)
        ->attribute(EngineRun::TRIGGER_MANUAL, 42, 'chain');

    $rebuilt = app(PayoutService::class)->runWeeklyBatch($batchDate);

    expect($rebuilt->created_by)->toBe(42);
    expect(PayoutLineItem::where('payout_batch_id', $rebuilt->id)
        ->get(['distributor_id', 'gross_paise', 'admin_charge_paise', 'tds_paise', 'net_transferred_paise'])
        ->toArray())->toBe($before);
    expect($distributor->id)->toBe($before[0]['distributor_id']);
});

it('does not sign off a batch that was un-built while the approval waited for the lock', function (): void {
    [$batch] = pendingWeeklyBatch();

    // approve() checks the status OUTSIDE the sweep lock, so an approval that
    // queued behind a rebuild arrives here holding a copy of a batch that no
    // longer exists. No money can move — the line items went with it — but a
    // `payout.batch.approved` audit row would record a finance decision on a
    // batch nobody can look at.
    app(PayoutService::class)->unbuildBatch($batch, 42, 'Rebuilding the week');

    $returned = app(PayoutService::class)->approve($batch, 77);

    expect($returned->status)->toBe(PayoutBatch::STATUS_PENDING);
    expect($returned->approved_at)->toBeNull();
    expect(AuditLog::where('action', 'payout.batch.approved')->exists())->toBeFalse();
    expect(PayoutBatch::find($batch->id))->toBeNull();
});
