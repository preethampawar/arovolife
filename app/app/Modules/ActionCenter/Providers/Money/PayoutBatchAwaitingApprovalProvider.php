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
 * A payout batch created and waiting to be signed off (plan §4, Money). The
 * acting permission is `finance.approve` — the maker-checker checker half
 * (QA F94) — not `finance.record`, which built the batch and cannot also
 * approve it.
 */
final class PayoutBatchAwaitingApprovalProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'payouts.batch_awaiting_approval';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Payout batches awaiting approval';
    }

    public function description(): string
    {
        return 'Payout batches built and waiting for a second person to sign them off.';
    }

    public function permission(): string
    {
        return 'finance.approve';
    }

    public function severity(): string
    {
        return Severity::WARNING;
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
            ->orderBy('payout_batches.created_at')
            ->limit($limit)
            ->get(['payout_batches.id', 'payout_batches.batch_type', 'payout_batches.batch_date', 'payout_batches.created_at', 'payout_batches.total_net_paise', 'payout_batches.distributor_count'])
            ->map(function (PayoutBatch $batch): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $batch->id,
                    title: ucfirst($batch->batch_type).' batch '.$batch->batch_date->toDateString(),
                    subtitle: 'Created '.$this->ageLabel($batch->created_at).' ago, awaiting approval',
                    occurredAt: $batch->created_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route(self::routeFor($batch), ['batch' => $batch->id]),
                    meta: [
                        'batch_type' => $batch->batch_type,
                        'total_net_paise' => (int) $batch->total_net_paise,
                        'distributor_count' => (int) $batch->distributor_count,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<PayoutBatch> */
    private function baseQuery(): Builder
    {
        $query = PayoutBatch::query()->where('payout_batches.status', PayoutBatch::STATUS_PENDING);

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
