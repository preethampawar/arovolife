<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A distributor with unswept payable income and no bank record on file.
 *
 * Mirrors, in SQL, the candidate set `PayoutService::sweepWeeklyBatch()` /
 * `sweepMonthlyBatch()` pull from `wallet_ledger_entries` (the four payable
 * GROUP type lists, unswept, positive, not reversed) and the same hold ladder
 * `PayoutService::holdStatusFor()` applies ahead of its bank-on-file check:
 * KYC must be active and personal BV must clear the NEFT minimum. A
 * distributor held for either of those reasons is not unblocked by adding a
 * bank record, so is deliberately left off this list — `kyc.pending_review`
 * already covers the KYC case elsewhere in the Action Center.
 *
 * If `holdStatusFor()` ever gains another gate ahead of its bank check, this
 * query must gain it too, or the two ladders silently drift.
 *
 * `PayoutService::hasBankAccountOnFile()` treats the pre-encryption `'stub'`
 * placeholder as present; everywhere else in the codebase (including this
 * provider) treats it as missing, because a stub can never decrypt to a real
 * account and will never be paid. No production row currently holds `'stub'`
 * — the discrepancy in `hasBankAccountOnFile()` itself is a separate,
 * out-of-scope fix.
 *
 * Identified by ADN only — the query never selects `bank_account_enc` or any
 * other PII column, only tests it in `WHERE` (CLAUDE.md PII discipline).
 */
final class PayoutsBankDetailsMissingProvider extends AbstractProvider
{
    public function __construct(private readonly CompensationPlanSettingsService $plan) {}

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
        return 'Distributors with payable income and no bank record on file. Bank details are self-service (profile → bank) or captured by an admin — ask the distributor to add theirs, or escalate for manual capture.';
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
        return $this->baseQuery()->toBase()->distinct()->count('wallet_ledger_entries.distributor_id');
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->selectRaw('wallet_ledger_entries.distributor_id, distributors.adn, '
                .'MIN(wallet_ledger_entries.created_at) as waiting_since, '
                .'SUM(wallet_ledger_entries.amount_paise) as unswept_gross_paise')
            ->groupBy('wallet_ledger_entries.distributor_id', 'distributors.id', 'distributors.adn')
            ->orderBy('waiting_since')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(function (object $row): ActionItem {
                $waitingSince = Carbon::parse($row->waiting_since);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $row->distributor_id,
                    title: $row->adn ?? "Distributor #{$row->distributor_id}",
                    subtitle: 'Income waiting '.$this->ageLabel($waitingSince).' ago, no bank record on file',
                    occurredAt: $waitingSince,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['id' => $row->distributor_id]),
                    meta: [
                        'adn' => $row->adn,
                        'unswept_gross_paise' => (int) $row->unswept_gross_paise,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<WalletLedgerEntry> */
    private function baseQuery(): Builder
    {
        $payableTypes = array_merge(
            CompensationPlanSettingsService::GROUP_A_TYPES,
            CompensationPlanSettingsService::GROUP_B_TYPES,
            CompensationPlanSettingsService::GROUP_C_TYPES,
            CompensationPlanSettingsService::GROUP_D_TYPES,
        );

        $query = WalletLedgerEntry::query()
            ->whereIn('wallet_ledger_entries.type', $payableTypes)
            ->whereNull('wallet_ledger_entries.swept_by_payout_batch_id')
            ->where('wallet_ledger_entries.amount_paise', '>', 0)
            ->notReversed()
            ->join('distributors', 'distributors.id', '=', 'wallet_ledger_entries.distributor_id')
            ->join('users', 'users.id', '=', 'distributors.user_id')
            ->where('users.status', 'active')
            ->where(function (Builder $bank): void {
                $bank->whereNull('distributors.bank_account_enc')
                    ->orWhere('distributors.bank_account_enc', '')
                    ->orWhere('distributors.bank_account_enc', 'stub');
            })
            ->whereRaw(
                '(select coalesce(sum(bv_paise), 0) from bv_ledger_entries '
                .'where bv_ledger_entries.distributor_id = distributors.id) >= ?',
                [$this->plan->neftMinBvPaise()],
            );

        $this->excludeSnoozed($query->getQuery(), 'distributors.id');

        return $query;
    }
}
