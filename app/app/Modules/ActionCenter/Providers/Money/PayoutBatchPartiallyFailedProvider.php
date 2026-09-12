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
 * Retry from the batch screen re-runs only the failed distributors.
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
        return 'Payout batches with at least one distributor still unpaid after processing. Retry the failed lines from the batch screen.';
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
            ->get(['payout_batches.id', 'payout_batches.batch_type', 'payout_batches.batch_date', 'payout_batches.processed_at', 'payout_batches.created_at'])
            ->map(function (PayoutBatch $batch): ActionItem {
                $occurredAt = $batch->processed_at ?? $batch->created_at;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $batch->id,
                    title: ucfirst($batch->batch_type).' batch '.$batch->batch_date->toDateString(),
                    subtitle: 'Processed '.$this->ageLabel($occurredAt).' ago, some distributors not paid',
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
