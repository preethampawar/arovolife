<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PayoutService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A distributor with unswept payable income whose bank account is ON FILE but
 * cannot be read back — `bank_decrypt_failed`, the last rung of
 * {@see PayoutService::holdStatusFor()}.
 *
 * The sibling of {@see PayoutsBankDetailsMissingProvider}, which covers the
 * three rungs above it. The two candidate sets are disjoint by construction:
 * that one wants `bank_account_enc` absent, empty or `'stub'`, this one wants
 * it present and none of those — so no distributor is reported twice.
 *
 * WHY THIS IS A DIFFERENT SEVERITY. The missing-details list is a distributor's
 * to-do: they have not given us their account yet. This is ours — they DID, and
 * the platform cannot read what it stored. Nobody outside can fix it and
 * nothing about it self-heals, so every batch from now until someone re-captures
 * the account holds their money.
 *
 * TWO ARMS, because the fault has two causes and only one of them is knowable
 * before a payout runs:
 *
 *   (a) The ciphertext is malformed — a plaintext account number written
 *       straight into the column by a seeder or a raw SQL fix-up. Laravel's
 *       encrypter always emits base64 of a JSON object beginning `{"iv":`, so
 *       a payload that does not start `eyJpdiI6` cannot be one of ours, whatever
 *       key is held. Caught the moment the bad row lands, days BEFORE the
 *       Tuesday it would have held.
 *
 *   (b) The payout engine has already recorded a `bank_decrypt_failed` line for
 *       them. That is the well-formed-but-unreadable case — a `PII_ENCRYPTION_KEY`
 *       that does not match the ciphertext, a row copied in from another
 *       environment — which no SQL predicate can detect, because deciding it
 *       needs the key and the plaintext. Caught the morning after.
 *
 * Deliberately NOT a third arm: trial-decrypting every candidate on page load.
 * It would close the gap in (b), and it would do so by pulling tens of thousands
 * of bank ciphertexts into a web request. This provider identifies people by ADN
 * and never selects `bank_account_enc` — only tests its shape in `WHERE`
 * (CLAUDE.md PII discipline) — and that is worth more than a few days' notice on
 * a fault whose held income is never swept and is paid in full by the first batch
 * after it is fixed.
 */
final class PayoutsBankUndecryptableProvider extends AbstractProvider
{
    /**
     * Base64 of `{"iv":` — the first six bytes of every payload
     * `Illuminate\Encryption\Encrypter` produces, and the only six that fall on
     * a clean base64 boundary (6 bytes → 8 characters, no padding). Key-blind on
     * purpose: this asks whether the column holds one of our ciphertexts at all,
     * not whether we can read it.
     */
    private const CIPHERTEXT_PREFIX = 'eyJpdiI6';

    public function __construct(private readonly CompensationPlanSettingsService $plan) {}

    public function key(): string
    {
        return 'payouts.bank_undecryptable';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Distributors whose bank details will not decrypt';
    }

    public function description(): string
    {
        return 'Distributors with payable income whose stored bank account cannot be read back, so every payout batch holds them. Re-capture the bank details from the distributor screen; the next batch pays the full balance.';
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
                    subtitle: 'Income waiting '.$this->ageLabel($waitingSince).' ago, bank details on file but unreadable',
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
            // Bank details ARE on file — the three rungs above this one belong
            // to PayoutsBankDetailsMissingProvider and must not be listed twice.
            ->whereNotNull('distributors.bank_account_enc')
            ->where('distributors.bank_account_enc', '!=', '')
            ->where('distributors.bank_account_enc', '!=', 'stub')
            ->where(function (Builder $unreadable): void {
                $unreadable
                    ->where('distributors.bank_account_enc', 'not like', self::CIPHERTEXT_PREFIX.'%')
                    ->orWhereExists(function ($held): void {
                        $held->selectRaw('1')
                            ->from('payout_line_items')
                            ->whereColumn('payout_line_items.distributor_id', 'distributors.id')
                            ->where('payout_line_items.status', PayoutLineItem::STATUS_BANK_DECRYPT_FAILED)
                            // A completed batch's holds are history. An open one
                            // is the money still waiting.
                            ->whereExists(function ($batch): void {
                                $batch->selectRaw('1')
                                    ->from('payout_batches')
                                    ->whereColumn('payout_batches.id', 'payout_line_items.payout_batch_id')
                                    ->where('payout_batches.status', '!=', PayoutBatch::STATUS_COMPLETED);
                            });
                    });
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
