<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Console\Commands\PayoutsReconcileCommand;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A payable line of an approved batch, handed to Razorpay an hour ago or more,
 * that was never sent: no payout id and still `pending`. A transfer job was
 * killed before it ran, and nothing but `payouts:reconcile` will send it. The
 * wallet was already debited, so the distributor is owed until it goes.
 */
final class PayoutsUnsentInDispatchedBatchProvider extends AbstractProvider
{
    /** How long a line may wait before it is listed. */
    private const THRESHOLD_HOURS = 1;

    public function key(): string
    {
        return 'payouts.unsent_in_dispatched_batch';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Approved payouts never sent';
    }

    public function description(): string
    {
        return 'Payable lines in a batch handed to Razorpay more than an hour ago that were never sent — usually a transfer job that was killed. payouts:reconcile re-queues them at 09:30 and 16:30.';
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
        return 'payout_line_item';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->with(['distributor:id,adn', 'payoutBatch:id,batch_type,batch_date,approved_at'])
            ->orderBy('payout_batches.approved_at')
            ->limit($limit)
            ->get()
            ->map(function (PayoutLineItem $line): ActionItem {
                $batch = $line->payoutBatch;
                $occurredAt = $batch->approved_at ?? $line->updated_at;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $line->id,
                    title: 'ADN '.($line->distributor->adn ?? $line->distributor_id).' — ₹'
                        .IndianNumber::format($line->net_transferred_paise / 100, 2),
                    subtitle: 'Batch approved '.$this->ageLabel($occurredAt).' ago, this line was never sent — payouts:reconcile re-queues it at 09:30 and 16:30',
                    occurredAt: $occurredAt,
                    dueAt: null,
                    severity: $this->severity(),
                    url: $batch !== null ? route(self::routeFor($batch), ['batch' => $batch->id]) : route('admin.compensation.weekly-payouts.index'),
                    meta: ['payout_batch_id' => (int) $line->payout_batch_id],
                );
            })
            ->values();
    }

    /** @return Builder<PayoutLineItem> */
    private function baseQuery(): Builder
    {
        $query = PayoutsReconcileCommand::unsentInDispatchedBatch(Carbon::now()->subHours(self::THRESHOLD_HOURS));

        $this->excludeSnoozed($query->getQuery(), 'payout_line_items.id');

        return $query;
    }

    private static function routeFor(PayoutBatch $batch): string
    {
        return $batch->batch_type === PayoutBatch::TYPE_MONTHLY
            ? 'admin.compensation.monthly-payouts.show'
            : 'admin.compensation.weekly-payouts.show';
    }
}
