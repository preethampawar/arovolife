<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A `manual_credit` wallet entry: counted in the displayed balance, swept by no
 * payout batch (R-74).
 *
 * `WalletService::balancePaise()` includes the type, so the distributor is shown
 * the money as owed; the weekly batch takes `GROUP_A_TYPES` and the monthly one
 * groups B, C and D, and `manual_credit` is in none of them. The row is
 * therefore displayed as payable and never transferred.
 *
 * The writer was deleted on 2026-09-17, and a source scan in
 * `CommissionHasProductSaleTest` fails if a new one appears — so on a clean
 * environment this provider is permanently empty and invisible. It exists for
 * the rows written before that date, which nothing else surfaces: the
 * alternative was a one-off query somebody had to remember to run against each
 * environment before a deploy, and a reconciliation that depends on being
 * remembered is one that eventually is not.
 *
 * Each row needs a decision written down, not a bulk fix: sweep it (which means
 * closing R-74 properly — the monthly sweep set AND this module's
 * `payouts.bank_details_missing` whitelist together), reverse it explicitly as a
 * written-off correction, or stop counting the type in the displayed balance.
 * On an environment with no live payout gateway (R-56) these are a display
 * defect, not withheld money.
 */
final class UnpayableManualCreditProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'compensation.unpayable_manual_credit';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Wallet credits no payout batch can pay';
    }

    public function description(): string
    {
        return 'Legacy manual_credit entries counted in the distributor\'s displayed balance but swept by neither payout batch. Each needs a written decision (R-74).';
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
        return 'wallet_ledger_entry';
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
            ->get();

        // WalletLedgerEntry carries no distributor relation, so the ADNs are
        // resolved in one query rather than one per row.
        $adns = Distributor::whereIn('id', $entries->pluck('distributor_id')->unique())
            ->pluck('adn', 'id');

        return $entries
            ->map(function (WalletLedgerEntry $entry) use ($adns): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $entry->id,
                    title: ($adns[$entry->distributor_id] ?? 'Distributor '.$entry->distributor_id)
                        .' — ₹'.IndianNumber::format($entry->amount_paise / 100, 2),
                    subtitle: 'Credited '.$this->ageLabel($entry->created_at).' ago, shown as owed and payable by no batch',
                    occurredAt: $entry->created_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route('admin.compensation.distributors.show', $entry->distributor_id),
                    meta: [
                        'amount_paise' => (int) $entry->amount_paise,
                        'memo' => $entry->memo,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<WalletLedgerEntry> */
    private function baseQuery(): Builder
    {
        $query = WalletLedgerEntry::query()
            ->where('wallet_ledger_entries.type', 'manual_credit');

        $this->excludeSnoozed($query->getQuery(), 'wallet_ledger_entries.id');

        return $query;
    }
}
