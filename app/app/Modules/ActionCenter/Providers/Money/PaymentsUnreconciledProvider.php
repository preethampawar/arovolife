<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Models\PaymentIntent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A payment the gateway reports captured whose order never advanced past
 * `placed` (plan §4, Money) — the exact gap `payments:reconcile` polls for
 * on open (created/authorised) intents; this catches the rarer case where the
 * gateway's own capture was never followed through to the order.
 */
final class PaymentsUnreconciledProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'payments.unreconciled';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Captured payments not reconciled';
    }

    public function description(): string
    {
        return 'Payments the gateway captured whose order was never marked paid. Sync the payment from the payments screen.';
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
        return 'payment_intent';
    }

    public function targetRoute(): string
    {
        return 'admin.payments.show';
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
            ->orderBy('payment_intents.captured_at')
            ->limit($limit)
            ->get(['payment_intents.id', 'payment_intents.order_id', 'payment_intents.captured_at', 'payment_intents.amount_paise'])
            ->map(function (PaymentIntent $intent): ActionItem {
                $occurredAt = $intent->captured_at ?? $intent->updated_at;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $intent->id,
                    title: $intent->order->order_no ?? "Order #{$intent->order_id}",
                    subtitle: 'Captured '.$this->ageLabel($occurredAt).' ago, order not marked paid',
                    occurredAt: $occurredAt,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['intent' => $intent->id]),
                    meta: [
                        'order_id' => $intent->order_id,
                        'amount_paise' => (int) $intent->amount_paise,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<PaymentIntent> */
    private function baseQuery(): Builder
    {
        $query = PaymentIntent::query()
            ->where('payment_intents.status', PaymentIntent::STATUS_CAPTURED)
            ->whereHas('order', fn ($q) => $q->where('orders.status', Order::STATUS_PLACED));

        $this->excludeSnoozed($query->getQuery(), 'payment_intents.id');

        return $query;
    }
}
