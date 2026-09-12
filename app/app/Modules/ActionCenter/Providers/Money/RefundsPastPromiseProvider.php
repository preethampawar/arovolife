<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Support\RefundWorklist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A refund sent or queued to the gateway longer ago than the contractual
 * seven-business-day promise (terms §8; plan §4, Money). Statutory: the
 * promise window is not a manager's to silence (plan §5).
 *
 * The business-day math is `RefundWorklist::classify()`'s alone — it is not
 * re-derived here. The base query narrows to candidates that are neither
 * failed nor currently held (classify()'s other two states); `classify()`
 * itself then decides which of those candidates is actually overdue.
 */
final class RefundsPastPromiseProvider extends AbstractProvider
{
    public function __construct(private readonly RefundWorklist $worklist) {}

    public function key(): string
    {
        return 'refunds.past_promise';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Refunds past the promise window';
    }

    public function description(): string
    {
        return 'Refunds sent or queued more than seven business days ago with no gateway confirmation. Chase the gateway or settle manually.';
    }

    public function permission(): string
    {
        return 'finance.record';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function statutory(): bool
    {
        return true;
    }

    public function subjectType(): string
    {
        return 'refund_intent';
    }

    public function targetRoute(): string
    {
        return 'admin.payments.refunds';
    }

    public function count(): int
    {
        return $this->overdueCandidates()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->overdueCandidates()
            ->take($limit)
            ->map(function (RefundIntent $refund): ActionItem {
                $classification = $this->worklist->classify($refund);
                $occurredAt = $refund->released_at ?? $refund->created_at;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $refund->id,
                    title: $refund->order->order_no ?? "Order #{$refund->order_id}",
                    subtitle: $classification['label'].' — '.$classification['days'].' business days, past the 7-day promise',
                    occurredAt: $occurredAt,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute()),
                    meta: [
                        'order_id' => $refund->order_id,
                        'amount_paise' => (int) $refund->amount_paise,
                        'business_days' => $classification['days'],
                    ],
                );
            })
            ->values();
    }

    /**
     * Non-failed, non-held candidates — the "sent"/"queued" states
     * `classify()` computes a business-day age for — oldest first, with
     * `classify()`'s verdict applied and the snoozed ones dropped.
     *
     * @return Collection<int, RefundIntent>
     */
    private function overdueCandidates(): Collection
    {
        return $this->candidateQuery()
            ->with('order:id,order_no')
            ->orderBy('refund_intents.created_at')
            ->get()
            ->filter(fn (RefundIntent $refund): bool => $this->worklist->classify($refund)['overdue'])
            ->values();
    }

    /** @return Builder<RefundIntent> */
    private function candidateQuery(): Builder
    {
        $query = RefundIntent::query()
            ->where('refund_intents.status', RefundIntent::STATUS_CREATED)
            ->where(fn ($q) => $q->whereNull('refund_intents.held_at')->orWhereNotNull('refund_intents.released_at'));

        $this->excludeSnoozed($query->getQuery(), 'refund_intents.id');

        return $query;
    }
}
