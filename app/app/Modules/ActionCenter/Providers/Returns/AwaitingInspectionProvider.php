<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Returns;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Returns\Models\ReturnRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Goods are back but nobody has recorded what condition they came back in
 * (plan §4, Orders & fulfilment).
 */
final class AwaitingInspectionProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'returns.awaiting_inspection';
    }

    public function group(): string
    {
        return ActionGroup::ORDERS;
    }

    public function label(): string
    {
        return 'Returns awaiting inspection';
    }

    public function description(): string
    {
        return 'Received returns with no inspection recorded yet. Log the condition from the return screen.';
    }

    public function permission(): string
    {
        return 'commerce.order.manage';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function slaHours(): int
    {
        return 48;
    }

    public function subjectType(): string
    {
        return 'return_request';
    }

    public function targetRoute(): string
    {
        return 'admin.returns.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('return_requests.received_at')
            ->limit($limit)
            ->get(['return_requests.id', 'return_requests.rma_no', 'return_requests.received_at', 'return_requests.order_id'])
            ->map(function (ReturnRequest $return): ActionItem {
                $dueAt = $this->dueAt($return->received_at);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $return->id,
                    title: $return->rma_no,
                    subtitle: 'Received '.$this->ageLabel($return->received_at).' ago, not inspected',
                    occurredAt: $return->received_at ?? now(),
                    dueAt: $dueAt,
                    severity: $this->itemSeverity($dueAt),
                    url: route($this->targetRoute(), ['return' => $return->id]),
                    meta: ['rma_no' => $return->rma_no, 'order_id' => $return->order_id],
                );
            })
            ->values();
    }

    /** @return Builder<ReturnRequest> */
    private function baseQuery(): Builder
    {
        $query = ReturnRequest::query()
            ->whereNotNull('return_requests.received_at')
            ->where('return_requests.received_at', '<=', $this->slaCutoff())
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('return_inspections')
                    ->whereColumn('return_inspections.return_request_id', 'return_requests.id');
            });

        $this->excludeSnoozed($query->getQuery(), 'return_requests.id');

        return $query;
    }
}
