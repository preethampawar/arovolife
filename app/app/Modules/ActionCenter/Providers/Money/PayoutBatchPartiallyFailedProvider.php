<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\PayoutBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A payout batch where at least one distributor's line item failed during
 * processing (plan §4, Money) — every other line item still stands and was
 * paid; this is the batch's own status, not a re-derived count of its lines.
 *
 * Two different situations share the status. Before approval it means the
 * BUILD failed for some distributors: nothing can be approved, and the next
 * Weekly/Monthly Run (or the CLI) retries only them. After approval it means
 * some transfers failed at the bank: retry those lines from the batch screen.
 */
final class PayoutBatchPartiallyFailedProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'payouts.batch_partially_failed';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Payout batches partially failed';
    }

    public function description(): string
    {
        return 'Payout batches with at least one distributor still unpaid. Not yet approved: the build failed for some distributors — the next Weekly/Monthly Run retries them, or re-run the batch from the command line (the batch screen shows the command). Approved: retry the failed lines from the batch screen.';
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
            ->orderBy('payout_batches.processed_at')
            ->limit($limit)
            ->get(['payout_batches.id', 'payout_batches.batch_type', 'payout_batches.batch_date', 'payout_batches.processed_at', 'payout_batches.created_at', 'payout_batches.approved_at'])
            ->map(function (PayoutBatch $batch): ActionItem {
                $occurredAt = $batch->processed_at ?? $batch->created_at;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $batch->id,
                    title: ucfirst($batch->batch_type).' batch '.$batch->batch_date->toDateString(),
                    subtitle: $batch->approved_at === null
                        ? 'Build failed for some distributors '.$this->ageLabel($occurredAt).' ago — retried by the next run, cannot be approved yet'
                        : 'Processed '.$this->ageLabel($occurredAt).' ago, some distributors not paid',
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
        $query = PayoutBatch::query()->where('payout_batches.status', PayoutBatch::STATUS_PARTIALLY_FAILED);

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
