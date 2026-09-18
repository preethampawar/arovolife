<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Support\FrozenPayoutGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** The batch that pays crediting month August 2026: dated 1 September 2026. */
function seedFrozenGuardBatch(string $status, ?string $approvedAt = null, ?string $processedAt = '2026-09-08 04:05:00'): PayoutBatch
{
    return PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-09-01',
        'earnings_through' => '2026-08-31',
        'status' => $status,
        'approved_at' => $approvedAt === null ? null : Carbon::parse($approvedAt),
        'processed_at' => $processedAt === null ? null : Carbon::parse($processedAt),
    ]);
}

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('does not freeze a month whose batch is still pending', function (): void {
    // Nobody has decided anything yet, so the month can still be recomputed.
    seedFrozenGuardBatch(PayoutBatch::STATUS_PENDING);

    expect(FrozenPayoutGuard::refusal(Carbon::parse('2026-08-01')))->toBeNull();
});

it('freezes a month whose batch finance approved even after the bank rejected it', function (): void {
    // The discriminator is `approved_at`, not the status: the money
    // instructions left the company, and the remedy is a per-line retry.
    $batch = seedFrozenGuardBatch(PayoutBatch::STATUS_FAILED, '2026-09-08 11:30:00');

    expect(FrozenPayoutGuard::isFrozen($batch))->toBeTrue();
    expect(FrozenPayoutGuard::refusal(Carbon::parse('2026-08-01')))->toContain('August 2026 is frozen');
});

it('freezes a month on the status alone', function (string $status): void {
    seedFrozenGuardBatch($status);

    expect(FrozenPayoutGuard::refusal(Carbon::parse('2026-08-01')))->not->toBeNull();
})->with([
    PayoutBatch::STATUS_APPROVED,
    PayoutBatch::STATUS_DISPATCHED,
    PayoutBatch::STATUS_COMPLETED,
    // Mid-sweep: an engine crediting into it now would race the sweep.
    PayoutBatch::STATUS_PROCESSING,
]);

it('freezes nothing when the month has no batch at all', function (): void {
    expect(FrozenPayoutGuard::refusal(Carbon::parse('2026-08-01')))->toBeNull();
    expect(FrozenPayoutGuard::frozenBatchFor(Carbon::parse('2026-08-01')))->toBeNull();
});

it('names the batch, its status and the date the monthly engines run again', function (): void {
    $batch = seedFrozenGuardBatch(PayoutBatch::STATUS_APPROVED, '2026-09-08 11:30:00');

    $refusal = FrozenPayoutGuard::refusal(Carbon::parse('2026-08-01'));

    expect($refusal)
        ->toContain("payout batch #{$batch->id} is approved (approved 08 Sep 2026 11:30)")
        ->toContain('money has moved on its figures')
        // The next month's engines fire on the 1st of the month after it.
        ->toContain('The monthly engines run next for September 2026, from 01 Oct 2026 00:00 IST.');
});

it('reads only the batch dated the month after the crediting month', function (): void {
    // A frozen batch for JULY must not freeze August.
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-08-01',
        'earnings_through' => '2026-07-31',
        'status' => PayoutBatch::STATUS_COMPLETED,
        'processed_at' => Carbon::parse('2026-08-08 04:05:00'),
    ]);

    expect(FrozenPayoutGuard::refusal(Carbon::parse('2026-07-01')))->not->toBeNull();
    expect(FrozenPayoutGuard::refusal(Carbon::parse('2026-08-01')))->toBeNull();
});

it('lets a month be credited while its batch has not been swept yet', function (): void {
    // A batch row with no `processed_at` is one the sweep has created and not
    // yet filled: nothing has been collected, so nothing is closed.
    seedFrozenGuardBatch(PayoutBatch::STATUS_PENDING, processedAt: null);

    expect(FrozenPayoutGuard::creditingRefusal(Carbon::parse('2026-08-01')))->toBeNull();
});

it('closes a swept-but-unapproved month to new credits without freezing it', function (): void {
    // A10. Between the 8th's sweep and finance's approval the month is still
    // rebuildable (so it is not frozen), but a credit written now would never be
    // picked up — `sweepMonthlyBatch()` returns a finalised pending batch
    // untouched — and finance would approve a batch that no longer matches the
    // ledger.
    $batch = seedFrozenGuardBatch(PayoutBatch::STATUS_PENDING);

    expect(FrozenPayoutGuard::isClosedToCredits($batch))->toBeTrue();
    expect(FrozenPayoutGuard::isFrozen($batch))->toBeFalse();
    expect(FrozenPayoutGuard::refusal(Carbon::parse('2026-08-01')))->toBeNull();
    expect(FrozenPayoutGuard::creditingRefusal(Carbon::parse('2026-08-01')))
        ->toContain("August 2026's payout batch #{$batch->id} was built on 08 Sep 2026 04:05 and awaits approval")
        ->toContain('compensation:rebuild-payout --month=2026-08');
});

it('gives an approved month the frozen refusal, not the awaiting-approval one', function (): void {
    seedFrozenGuardBatch(PayoutBatch::STATUS_APPROVED, '2026-09-08 11:30:00');

    expect(FrozenPayoutGuard::creditingRefusal(Carbon::parse('2026-08-01')))
        ->toContain('August 2026 is frozen')
        ->not->toContain('awaits approval');
});
