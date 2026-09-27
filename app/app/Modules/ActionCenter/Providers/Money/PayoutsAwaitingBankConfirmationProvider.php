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
 * A transfer Razorpay accepted a day ago or more that is still `pending`: the
 * webhook that would settle it never arrived. The distributor's wallet was
 * debited when the batch was built, so until the line settles nobody can say
 * whether they were paid. `payouts:reconcile` asks Razorpay twice a day; this
 * item is what stays when even that cannot settle it.
 */
final class PayoutsAwaitingBankConfirmationProvider extends AbstractProvider
{
    /** How long a line may wait before it is listed. */
    private const THRESHOLD_HOURS = 24;

    public function key(): string
    {
        return 'payouts.awaiting_bank_confirmation';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Payouts waiting on the bank';
    }

    public function description(): string
    {
        return 'Transfers Razorpay accepted more than a day ago with no confirmation since. payouts:reconcile asks Razorpay about them at 09:30 and 16:30; use Check with Razorpay on the batch page to ask now.';
    }

    public function permission(): string
    {
        return 'finance.record';
    }

    public function severity(): string
    {
        return Severity::WARNING;
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
            ->orderBy('payout_line_items.dispatched_at')
            ->limit($limit)
            ->get()
            ->map(function (PayoutLineItem $line): ActionItem {
                $batch = $line->payoutBatch;
                $occurredAt = $line->dispatched_at ?? $line->updated_at;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $line->id,
                    title: 'ADN '.($line->distributor->adn ?? $line->distributor_id).' — ₹'
                        .IndianNumber::format($line->net_transferred_paise / 100, 2),
                    subtitle: 'Sent to Razorpay '.$this->ageLabel($occurredAt).' ago, no confirmation yet',
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
        $query = PayoutsReconcileCommand::awaitingBank(Carbon::now()->subHours(self::THRESHOLD_HOURS));

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
