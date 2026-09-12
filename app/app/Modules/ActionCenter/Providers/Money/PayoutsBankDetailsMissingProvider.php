<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\PayoutLineItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A distributor whose most recent payout line item was held for having no
 * usable bank record (plan §4, Money) — `PayoutLineItem::STATUS_NO_BANK_ACCOUNT`,
 * the exact status `PayoutService::hasBankAccountOnFile()` assigns when the
 * distributor's `bank_account_enc` is empty or still the pre-encryption
 * 'stub' placeholder that will never decrypt to a real account number.
 *
 * "No usable bank record" is read off the platform's own most recent
 * judgement of that distributor rather than re-summing every wallet ledger:
 * a distributor whose LATEST line item (across every batch, by id) still
 * carries this status has payable or held income sitting behind it with
 * nothing to pay it into. A distributor who has since added bank details and
 * been paid or held for a different reason drops out the moment a newer line
 * item is written for them.
 */
final class PayoutsBankDetailsMissingProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'payouts.bank_details_missing';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Distributors missing bank details';
    }

    public function description(): string
    {
        return 'Distributors with income held for want of a usable bank record. Capture their bank details from the distributor screen.';
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
        return 'distributor';
    }

    public function targetRoute(): string
    {
        return 'admin.distributors.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->join('distributors', 'distributors.id', '=', 'payout_line_items.distributor_id')
            ->orderBy('payout_line_items.created_at')
            ->limit($limit)
            ->get(['payout_line_items.id', 'payout_line_items.distributor_id', 'payout_line_items.gross_paise', 'payout_line_items.created_at', 'distributors.adn'])
            ->map(function (PayoutLineItem $line): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $line->distributor_id,
                    title: $line->getAttribute('adn') ?? "Distributor #{$line->distributor_id}",
                    subtitle: 'Income held '.$this->ageLabel($line->created_at).' ago, no usable bank record on file',
                    occurredAt: $line->created_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['id' => $line->distributor_id]),
                    meta: ['gross_paise' => (int) $line->gross_paise],
                );
            })
            ->values();
    }

    /** @return Builder<PayoutLineItem> */
    private function baseQuery(): Builder
    {
        $query = PayoutLineItem::query()
            ->where('payout_line_items.status', PayoutLineItem::STATUS_NO_BANK_ACCOUNT)
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('payout_line_items as later')
                    ->whereColumn('later.distributor_id', 'payout_line_items.distributor_id')
                    ->whereColumn('later.id', '>', 'payout_line_items.id');
            });

        $this->excludeSnoozed($query->getQuery(), 'payout_line_items.distributor_id');

        return $query;
    }
}
