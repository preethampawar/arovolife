<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A distributor whose payout the bank did not complete: a `failed` line in an
 * approved batch, with money owed.
 *
 * The wallet was debited when the batch was built, so until someone sends the
 * line again (or records that it was paid after all) the distributor is owed
 * money that nothing else will pick up — no later batch sweeps it, and in
 * Manual NEFT mode no sweep retries it. One item per line, not per batch, so a
 * single bounced transfer in an otherwise completed batch is not hidden.
 */
final class PayoutsFailedAwaitingResendProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'payouts.failed_awaiting_resend';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Failed payouts waiting to be sent again';
    }

    public function description(): string
    {
        return 'Distributors whose payout the bank did not complete. Their wallet was already debited, so nothing else will pay them. Fix the cause (usually bank details), then use Send again on the batch page — or Mark paid if the bank confirms it went through.';
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
            ->with(['distributor:id,adn', 'payoutBatch:id,batch_type,batch_date'])
            ->orderBy('payout_line_items.updated_at')
            ->limit($limit)
            ->get(['payout_line_items.id', 'payout_line_items.payout_batch_id', 'payout_line_items.distributor_id',
                'payout_line_items.net_transferred_paise', 'payout_line_items.failure_reason', 'payout_line_items.updated_at'])
            ->map(function (PayoutLineItem $line): ActionItem {
                $batch = $line->payoutBatch;
                $reason = $line->failure_reason !== null ? ' — '.mb_substr($line->failure_reason, 0, 80) : '';

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $line->id,
                    title: 'ADN '.($line->distributor->adn ?? $line->distributor_id).' · ₹'
                        .IndianNumber::format($line->net_transferred_paise / 100, 2),
                    subtitle: 'Failed '.$this->ageLabel($line->updated_at).' ago'.$reason,
                    occurredAt: $line->updated_at,
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
        $query = PayoutLineItem::query()
            ->where('payout_line_items.status', PayoutLineItem::STATUS_FAILED)
            ->where('payout_line_items.net_transferred_paise', '>', 0)
            ->whereExists(function ($batch): void {
                $batch->selectRaw('1')
                    ->from('payout_batches')
                    ->whereColumn('payout_batches.id', 'payout_line_items.payout_batch_id')
                    ->whereNotNull('payout_batches.approved_at');
            });

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
