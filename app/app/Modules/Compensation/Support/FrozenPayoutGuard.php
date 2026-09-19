<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Models\PayoutBatch;
use Illuminate\Support\Carbon;

/**
 * Once finance has signed off a month's payout, that month's figures are final.
 *
 * The client's rule (2026-09-18): "once the admin freezes the payout, every
 * monthly engine for that month is disabled until the next 1st". The moment of
 * freezing is finance APPROVING the batch — `approved_at IS NOT NULL`, or a
 * status past pending. A pending batch does not freeze the month: nobody has
 * decided anything yet, so it can still be rebuilt.
 *
 * Why it has no override, unlike {@see OpenMonthGuard}'s `--in-flight`: money
 * has left on these figures. A monthly engine that ran again would credit into
 * a month whose payment has already been instructed — a credit nothing will
 * ever sweep, because the batch that would have swept it is closed, and a
 * distributor's own statement that no longer reconciles with what they were
 * paid. `--force` is for an operator who accepts a partial month; there is no
 * operator who can accept a second payment for a month already paid.
 *
 * "Enabled again on the 1st 00:00 IST for the next month" needs no mechanism:
 * the next month has no frozen batch, and {@see OpenMonthGuard} already refuses
 * it until it has ended.
 *
 * `processing` counts as frozen so an engine cannot credit into a month whose
 * batch is mid-sweep; a batch stuck in `processing` has its own remedy
 * (`payout:reopen-stuck-batch`), which is not this guard's business.
 */
final class FrozenPayoutGuard
{
    /** Statuses that mean finance's hand is on the batch, or a sweep is in flight. */
    public const FROZEN_STATUSES = [
        PayoutBatch::STATUS_APPROVED,
        PayoutBatch::STATUS_DISPATCHED,
        PayoutBatch::STATUS_COMPLETED,
        PayoutBatch::STATUS_PROCESSING,
    ];

    /**
     * `approved_at` OR the status, never the status alone: a batch finance
     * approved that the bank then rejected reads `failed` or `partially_failed`
     * — the money instructions still left the company, and its remedy is a
     * per-line retry, not a recomputation of the month.
     */
    public static function isFrozen(PayoutBatch $batch): bool
    {
        return $batch->approved_at !== null
            || in_array($batch->status, self::FROZEN_STATUSES, true);
    }

    /**
     * The monthly batch that pays crediting month $month — dated the 1st of the
     * FOLLOWING month, which is the month the money moves in.
     */
    public static function batchFor(Carbon $creditingMonth): ?PayoutBatch
    {
        return PayoutBatch::query()
            ->where('batch_type', PayoutBatch::TYPE_MONTHLY)
            ->whereDate('batch_date', $creditingMonth->copy()->startOfMonth()->addMonthNoOverflow()->toDateString())
            // One batch per type per date IS a database constraint
            // (`uniq_payout_batch_date_type`), so this can only ever match one
            // row today. Newest first is defence in depth for a future path
            // that drops the index: an unordered `first()` would then hand the
            // guard an arbitrary row rather than the batch finance is looking
            // at.
            ->orderByDesc('id')
            ->first();
    }

    public static function frozenBatchFor(Carbon $creditingMonth): ?PayoutBatch
    {
        $batch = self::batchFor($creditingMonth);

        return $batch !== null && self::isFrozen($batch) ? $batch : null;
    }

    /**
     * The batch has been BUILT and is waiting for finance — pending, with
     * `processed_at` set.
     *
     * Not frozen: nobody has approved anything, so the month is still
     * rebuildable (a rebuild un-builds the batch first, which is why the
     * rebuilders read {@see self::isFrozen()} and not this). But it is closed
     * to new credits all the same. `PayoutService::sweepMonthlyBatch()` returns
     * a finalised pending batch untouched, so a credit written into the month
     * now would never be swept by it, and finance would approve a batch that no
     * longer matches the ledger it was built from.
     */
    public static function isClosedToCredits(PayoutBatch $batch): bool
    {
        return $batch->status === PayoutBatch::STATUS_PENDING && $batch->processed_at !== null;
    }

    /**
     * May anything still be CREDITED into this month? Null when it may.
     *
     * Two different refusals, one question, so no caller has to know there are
     * two: the month is frozen (money has moved — nothing may run for it
     * again), or its batch is built and awaiting approval (nothing may be
     * credited, but the batch can still be rebuilt once the engine is fixed).
     * The crediting engines and the monthly close ask this; the rebuilders ask
     * {@see self::refusal()}.
     */
    public static function creditingRefusal(Carbon $creditingMonth): ?string
    {
        $frozen = self::refusal($creditingMonth);

        if ($frozen !== null) {
            return $frozen;
        }

        $batch = self::batchFor($creditingMonth);

        if ($batch === null || ! self::isClosedToCredits($batch)) {
            return null;
        }

        $month = $creditingMonth->copy()->startOfMonth();

        return sprintf(
            "%s's payout batch #%d was built on %s and awaits approval; nothing may be credited into %s now — "
            .'a credit written after the sweep would never be picked up by it, and finance would approve a batch '
            .'that no longer matches the ledger. After this engine is fixed, rebuild the payout '
            .'(php artisan compensation:rebuild-payout --month=%s) so the batch matches the ledger.',
            $month->format('F Y'),
            $batch->id,
            ($batch->processed_at ?? $batch->created_at)?->format('d M Y H:i') ?? 'an earlier date',
            $month->format('F Y'),
            $month->format('Y-m'),
        );
    }

    /** The refusal to print, or null when the month's payout is not frozen. */
    public static function refusal(Carbon $creditingMonth): ?string
    {
        $batch = self::frozenBatchFor($creditingMonth);

        if ($batch === null) {
            return null;
        }

        $month = $creditingMonth->copy()->startOfMonth();

        return sprintf(
            '%s is frozen: its payout batch #%d is %s%s. No monthly engine, close or rebuild may run for %s again — '
            .'money has moved on its figures. The monthly engines run next for %s, from %s 00:00 IST.',
            $month->format('F Y'),
            $batch->id,
            $batch->status,
            $batch->approved_at !== null ? ' (approved '.$batch->approved_at->format('d M Y H:i').')' : '',
            $month->format('F Y'),
            $month->copy()->addMonthNoOverflow()->format('F Y'),
            $month->copy()->addMonthsNoOverflow(2)->format('d M Y'),
        );
    }
}
