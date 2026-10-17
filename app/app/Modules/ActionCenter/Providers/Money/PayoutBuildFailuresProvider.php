<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\PayoutBuildFailure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A distributor a payout batch skipped because their line could not be built
 * (user 2026-10-17). The batch carried on for everyone else; this distributor
 * is unpaid until a retry builds them or a later batch pays them.
 */
final class PayoutBuildFailuresProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'payouts.build_failures';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Distributors skipped by a payout batch';
    }

    public function description(): string
    {
        return 'Distributors whose payout line could not be built, so the batch went ahead without them. Fix the cause and retry them on Payouts → Build failures before the batch is approved, or they are paid by the next batch of the same type.';
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
        return 'payout_build_failure';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->with(['payoutBatch', 'distributor'])
            ->orderBy('payout_build_failures.first_failed_at')
            ->limit($limit)
            ->get()
            ->map(function (PayoutBuildFailure $failure): ActionItem {
                $batch = $failure->payoutBatch;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $failure->id,
                    title: ($failure->distributor->adn ?? 'Distributor #'.$failure->distributor_id)
                        .' — '.($batch !== null ? $batch->batch_type.' batch '.$batch->batch_date->toDateString() : 'payout batch'),
                    subtitle: class_basename($failure->exception_class).', '.$failure->attempts.' attempt(s), first failed '
                        .$this->ageLabel($failure->first_failed_at).' ago'
                        .($batch?->approved_at !== null ? ' — batch approved, paid by the next one' : ''),
                    occurredAt: $failure->first_failed_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route('admin.compensation.payout-failures.index', ['batch' => $failure->payout_batch_id]),
                    meta: ['payout_batch_id' => $failure->payout_batch_id],
                );
            })
            ->values();
    }

    /** @return Builder<PayoutBuildFailure> */
    private function baseQuery(): Builder
    {
        $query = PayoutBuildFailure::query()
            ->whereIn('payout_build_failures.status', PayoutBuildFailure::UNRESOLVED_STATUSES);

        $this->excludeSnoozed($query->getQuery(), 'payout_build_failures.id');

        return $query;
    }
}
