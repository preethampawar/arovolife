<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * A cash Lifetime Award the monthly payout is holding back because the rank
 * qualification it was earned on no longer stands (R7, compliance): a rebuild,
 * a voided qualification or a refund re-run took the rank away after the award
 * was credited. The payout leaves the awards_credit unswept and re-checks it on
 * every later batch (PayoutService::withoutUnqualifiedAwards()).
 *
 * Each row needs a decision: withdraw the award on the Lifetime Awards page
 * (the rank was not earned), or restore the qualification if its removal was
 * the mistake. Mirrors LifetimeAwardService::milestonesNoLongerQualified() in
 * SQL — change both together.
 */
final class AwardRequalificationCheckProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'compensation.award_requalification_check';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Lifetime Awards held for a rank re-check';
    }

    public function description(): string
    {
        return 'Credited Lifetime Awards the payout will not pay because the rank qualification they were earned on no longer stands. Withdraw the award, or restore the qualification.';
    }

    public function permission(): string
    {
        return 'compliance.discipline';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function enabled(): bool
    {
        return Feature::for(null)->active(LifetimeAwardsFeature::class);
    }

    public function subjectType(): string
    {
        return 'wallet_ledger_entry';
    }

    public function targetRoute(): string
    {
        return 'admin.lifetime-awards.index';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        $entries = $this->baseQuery()
            ->orderBy('wallet_ledger_entries.created_at')
            ->limit($limit)
            ->get(['wallet_ledger_entries.*']);

        $adns = Distributor::whereIn('id', $entries->pluck('distributor_id')->unique())
            ->pluck('adn', 'id');

        return $entries
            ->map(function (WalletLedgerEntry $entry) use ($adns): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $entry->id,
                    title: ($adns[$entry->distributor_id] ?? 'Distributor '.$entry->distributor_id)
                        .' — ₹'.IndianNumber::format($entry->amount_paise / 100, 2),
                    subtitle: 'Credited '.$this->ageLabel($entry->created_at).' ago; its rank qualification no longer stands',
                    occurredAt: $entry->created_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route('admin.lifetime-awards.index'),
                    meta: [
                        'amount_paise' => (int) $entry->amount_paise,
                        'milestone_id' => (int) $entry->reference_id,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<WalletLedgerEntry> */
    private function baseQuery(): Builder
    {
        $query = WalletLedgerEntry::query()
            ->where('wallet_ledger_entries.type', 'awards_credit')
            ->where('wallet_ledger_entries.reference_type', 'lifetime_award_milestone')
            ->whereNull('wallet_ledger_entries.swept_by_payout_batch_id')
            ->notReversed()
            ->join('lifetime_award_milestones as lam', 'lam.id', '=', 'wallet_ledger_entries.reference_id')
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('rank_qualifications as rq')
                    ->whereColumn('rq.distributor_id', 'lam.distributor_id')
                    ->whereColumn('rq.rank_number', 'lam.rank_number')
                    ->whereRaw('rq.month_start = COALESCE(lam.achieved_month, lam.triggered_month)')
                    ->where('rq.status', RankQualification::STATUS_QUALIFIED)
                    ->where('rq.is_carry_forward', false);
            });

        $this->excludeSnoozed($query->getQuery(), 'wallet_ledger_entries.id');

        return $query;
    }
}
