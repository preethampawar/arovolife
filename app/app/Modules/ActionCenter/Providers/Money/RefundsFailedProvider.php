<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Payments\Models\RefundIntent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A refund attempt the gateway rejected outright (plan §4, Money) — the same
 * "failed, not forfeited" filter `RefundWorklist::attentionCount()` uses: a
 * refund whose goods were never returned is closed, not failed, so it is
 * excluded here too rather than re-deriving the distinction.
 */
final class RefundsFailedProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'refunds.failed';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Refunds needing manual settlement';
    }

    public function description(): string
    {
        return 'Refund attempts the gateway rejected. Settle or retry them from the refunds screen.';
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
        return 'refund_intent';
    }

    public function targetRoute(): string
    {
        return 'admin.payments.refunds';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->with('order:id,order_no')
            ->orderBy('refund_intents.failed_at')
            ->limit($limit)
            ->get(['refund_intents.id', 'refund_intents.order_id', 'refund_intents.failed_at', 'refund_intents.updated_at', 'refund_intents.amount_paise', 'refund_intents.error_code'])
            ->map(function (RefundIntent $refund): ActionItem {
                $occurredAt = $refund->failed_at ?? $refund->updated_at;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $refund->id,
                    title: $refund->order->order_no ?? "Order #{$refund->order_id}",
                    subtitle: 'Refund failed '.$this->ageLabel($occurredAt).' ago, needs manual settlement',
                    occurredAt: $occurredAt,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute()),
                    meta: [
                        'order_id' => $refund->order_id,
                        'amount_paise' => (int) $refund->amount_paise,
                        'error_code' => $refund->error_code,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<RefundIntent> */
    private function baseQuery(): Builder
    {
        $query = RefundIntent::query()
            ->where('refund_intents.status', RefundIntent::STATUS_FAILED)
            ->where(fn ($q) => $q->whereNull('refund_intents.error_code')->orWhere('refund_intents.error_code', '!=', RefundIntent::ERROR_GOODS_NOT_RETURNED));

        $this->excludeSnoozed($query->getQuery(), 'refund_intents.id');

        return $query;
    }
}
