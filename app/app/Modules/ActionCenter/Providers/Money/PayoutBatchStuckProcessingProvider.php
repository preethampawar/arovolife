<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Console\Commands\PayoutReopenStuckBatchCommand;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\PayoutService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A payout batch still reading `processing` long after any sweep could still
 * be running it.
 *
 * `processing` is written when a sweep starts a batch and replaced the moment
 * it ends — by `pending` or `partially_failed` when it finishes, by `failed`
 * when it throws. A row that keeps it is therefore a sweep that ended without
 * running any of its own code: OOM, the queue job's hour-long timeout, SIGKILL.
 *
 * NOTHING ELSE SURFACES THIS, which is why it is here. The night that died is
 * reported as a failed chain and cleared by the retry button, and the retry
 * legitimately "succeeds" — the nightly chain sees a batch exists for that
 * Tuesday and moves on, because it proves a batch from the batch and not from
 * its status. So the banner goes green over a batch that is stuck for good and
 * a week of distributors whose line items were never written. (Their money is
 * safe: unswept income is picked up by the following Tuesday's batch. What is
 * lost is the week, and the truth of the payout report.)
 *
 * The fix is `php artisan payout:reopen-stuck-batch`, deliberately not a button
 * — see {@see PayoutReopenStuckBatchCommand}.
 *
 * The threshold IS `PayoutService::STUCK_BATCH_MIN_IDLE_SECONDS`, read from the
 * service rather than restated here: a tile that fires before the command would
 * act only teaches people to ignore it, and two copies of one number drift the
 * first time anybody tunes the lock.
 *
 * Liveness is measured the same way the service measures it, and for the same
 * reason: `payout_batches.updated_at` is written twice per sweep — at the start
 * and at the end — so on its own it says when the sweep STARTED. The
 * per-distributor line item is the heartbeat.
 */
final class PayoutBatchStuckProcessingProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'payouts.batch_stuck_processing';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Payout batches stuck mid-sweep';
    }

    public function description(): string
    {
        return 'Payout batches still marked `processing` long after the sweep that started them could have finished — the sweep was killed outright. Nothing re-enters them on its own; reopen with `php artisan payout:reopen-stuck-batch` and re-run the batch.';
    }

    public function permission(): string
    {
        return 'finance.record';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function subjectType(): string
    {
        return 'payout_batch';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('payout_batches.updated_at')
            ->limit($limit)
            ->get(['payout_batches.id', 'payout_batches.batch_type', 'payout_batches.batch_date', 'payout_batches.updated_at', 'payout_batches.created_at'])
            ->map(function (PayoutBatch $batch): ActionItem {
                $occurredAt = $batch->updated_at ?? $batch->created_at;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $batch->id,
                    title: ucfirst($batch->batch_type).' batch '.$batch->batch_date->toDateString(),
                    subtitle: 'Stuck in processing since '.$this->ageLabel($occurredAt).' ago — the sweep was killed',
                    occurredAt: $occurredAt,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route(self::routeFor($batch), ['batch' => $batch->id]),
                    meta: ['batch_type' => $batch->batch_type],
                );
            })
            ->values();
    }

    /** @return Builder<PayoutBatch> */
    private function baseQuery(): Builder
    {
        $idleBefore = now()->subSeconds(PayoutService::STUCK_BATCH_MIN_IDLE_SECONDS);

        $query = PayoutBatch::query()
            ->where('payout_batches.status', PayoutBatch::STATUS_PROCESSING)
            ->where('payout_batches.updated_at', '<', $idleBefore)
            ->whereRaw(
                'coalesce((select max(created_at) from payout_line_items '
                .'where payout_line_items.payout_batch_id = payout_batches.id), payout_batches.updated_at) < ?',
                [$idleBefore],
            );

        $this->excludeSnoozed($query->getQuery(), 'payout_batches.id');

        return $query;
    }

    private static function routeFor(PayoutBatch $batch): string
    {
        return $batch->batch_type === PayoutBatch::TYPE_MONTHLY
            ? 'admin.compensation.monthly-payouts.show'
            : 'admin.compensation.weekly-payouts.show';
    }
}
