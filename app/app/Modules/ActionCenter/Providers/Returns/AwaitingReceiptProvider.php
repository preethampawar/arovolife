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
 * A refund is on hold waiting for the goods to actually come back (plan §4,
 * Orders & fulfilment) — mirrors `ReturnRequest::isAwaitingReceipt()`
 * exactly. Statutory: the customer's money is held against a promise, not a
 * manager's queue to defer (plan §5). The 10-day figure is the alert tier;
 * the 21-day escalation the plan also names is handled by the existing hold
 * sweep, not by severity promotion here — this type is already critical, its
 * ceiling.
 */
final class AwaitingReceiptProvider extends AbstractProvider
{
    private const ALERT_DAYS = 10;

    public function key(): string
    {
        return 'returns.awaiting_receipt';
    }

    public function group(): string
    {
        return ActionGroup::ORDERS;
    }

    public function label(): string
    {
        return 'Refunds waiting on the return';
    }

    public function description(): string
    {
        return 'Refund entitlements held pending receipt of the returned goods. Chase the courier or the customer.';
    }

    public function permission(): string
    {
        return 'commerce.order.manage';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function statutory(): bool
    {
        return true;
    }

    public function slaHours(): int
    {
        return self::ALERT_DAYS * 24;
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
            ->orderBy('return_requests.entitlements_held_at')
            ->limit($limit)
            ->get(['return_requests.id', 'return_requests.rma_no', 'return_requests.entitlements_held_at', 'return_requests.order_id'])
            ->map(function (ReturnRequest $return): ActionItem {
                $dueAt = $this->dueAt($return->entitlements_held_at);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $return->id,
                    title: $return->rma_no,
                    subtitle: 'Refund held '.$this->ageLabel($return->entitlements_held_at).' ago, goods not received',
                    occurredAt: $return->entitlements_held_at ?? now(),
                    dueAt: $dueAt,
                    severity: $this->severity(),
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
            ->whereNotNull('return_requests.entitlements_held_at')
            ->whereNull('return_requests.received_at')
            ->whereNull('return_requests.receipt_outcome');

        // Statutory (plan §5): nothing can snooze this type in practice, but
        // excluding on principle keeps every provider's count() honouring the
        // same rule (plan §3.1.2) rather than one type being a special case.
        $this->excludeSnoozed($query->getQuery(), 'return_requests.id');

        return $query;
    }
}
