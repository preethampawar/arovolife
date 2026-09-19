<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Rebuild;

use App\Modules\Commerce\Models\PurchaseOfferGrant;
use App\Modules\Commerce\Models\RedeemPointEntry;
use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Models\FortuneMonthlyPool;
use App\Modules\Compensation\Models\FortuneMonthlyPoolLevel;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankMonthlyPool;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Support\FrozenPayoutGuard;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\OpenMonthGuard;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Rebuild one crediting month M: un-build its unapproved payout batch if one
 * exists, delete every row the seven crediting engines derived for M and the
 * wallet entries those rows produced, then let `compensation:monthly-close` run
 * the month again with `--restart`.
 *
 * ONLY WHILE NOTHING LATER HAS BEEN BUILT ON IT (D12). The month's ranks,
 * repurchase-wallet verdicts and Fortune roster are read by the NEXT month's
 * engines, so once M+1 has credited anything, M is load-bearing and can no
 * longer be moved underneath it; and once finance has approved M's payout the
 * month is frozen outright (D9, no override).
 *
 * Three kinds of row survive on purpose, each because something outside the
 * engines acted on it:
 *
 *  - a purchase-offer grant an order has already consumed (F124 — re-deriving
 *    it would let the same order's discount be taken twice);
 *  - a lifetime-award milestone already delivered or cancelled (a hand-released
 *    award, not an engine output);
 *  - a legacy `is_carry_forward` rank qualification written by an EARLIER
 *    month's check, which belongs to that month and not to this one.
 */
final class MonthRebuilder
{
    /**
     * The wallet rows a monthly credit consists of: the gross, and the two
     * halves of the credit-time repurchase deduction.
     *
     * `awards_credit` is absent deliberately — a lifetime award is released by
     * hand, not by an engine, and a rebuild does not take one back.
     *
     * @var list<string>
     */
    private const WALLET_TYPES = [
        'gbb_credit', 'rank_credit', 'fortune_credit', 'adc_credit',
        'repurchase_transfer', 'repurchase_deduction',
    ];

    /** How many ids go into one `whereIn`. */
    private const CHUNK = 5_000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly EngineStatusService $status,
        private readonly PayoutService $payouts,
    ) {}

    public function plan(Carbon $month): RebuildPlan
    {
        $month = $month->copy()->startOfMonth();
        $batch = FrozenPayoutGuard::batchFor($month);

        // The batch this rebuild would un-build, and everything that comes with
        // it. Read through the same preview PayoutService::unbuildBatch() is
        // measured by, so the two never describe different rows: a batch's own
        // debits and forfeits are wallet rows the wipe deletes, and counting
        // only the credits made a 400-line batch preview half of what it
        // removes.
        $unbuilding = $batch !== null && ! FrozenPayoutGuard::isFrozen($batch) ? $batch : null;
        $unbuild = $unbuilding === null ? null : $this->payouts->unbuildPreview($unbuilding);
        $unsweeps = $unbuild === null ? 0 : $unbuild['entries_unswept'];

        $grantIds = $this->ids($this->regrantableGrants($month));

        $rows = [
            'wallet_ledger_entries' => $this->countWalletEntries($month)
                + ($unbuild === null ? 0 : $unbuild['debits_deleted'] + $unbuild['forfeits_deleted']),
            'rank_aogo_grants' => $this->aogoGrants($month)->count(),
            'rank_bonus_results' => $this->rankResults($month)->count(),
            'rank_monthly_pools' => $this->rankPools($month)->count(),
            'rank_qualifications' => $this->rankQualifications($month)->count(),
            'lifetime_award_milestones' => $this->pendingMilestones($month)->count(),
            'gbb_monthly_results' => $this->gbbResults($month)->count(),
            'gbb_monthly_pools' => $this->gbbPools($month)->count(),
            'fortune_bonus_results' => $this->fortuneResults($month)->count(),
            'fortune_monthly_pool_levels' => $this->fortunePoolLevels($month)->count(),
            'fortune_monthly_pools' => $this->fortunePools($month)->count(),
            'fortune_bonus_participants' => $this->fortuneParticipants($month)->count(),
            'adc_bonus_results' => $this->adcResults($month)->count(),
            'redeem_point_entries' => $this->redeemEntriesFor($grantIds)->count(),
            'purchase_offer_grants' => count($grantIds),
        ];

        if ($unbuild !== null) {
            $rows['payout_line_items'] = $unbuild['line_items'];
            $rows['payout_batches'] = 1;
        }

        return new RebuildPlan(
            RebuildKind::Month,
            $month,
            $this->refusals($month, $batch),
            $this->warnings($month, $batch),
            array_filter($rows, static fn (int $count): bool => $count > 0),
            $unsweeps,
        );
    }

    /**
     * @param  Closure(string): void  $log
     * @param  Closure(): void  $verify  Re-asks the refusals before the first delete; throws if anything moved.
     */
    public function wipe(Carbon $month, int $actorId, Closure $log, Closure $verify): RebuildResult
    {
        $month = $month->copy()->startOfMonth();
        $removed = [];

        // Before anything is taken apart: the preview and the confirm are two
        // moments, and a month that acquired a refusal in between is not the
        // month anybody agreed to rebuild.
        $verify();

        // How much credited income goes, read while it is still there.
        $walletTotals = $this->walletTotals($month);

        // Its own transaction and its own audit row, before the month's rows go:
        // the batch is what stamped `swept_by_payout_batch_id` on the credits
        // this wipe is about to delete, and a credit cannot be deleted while a
        // batch still claims to have paid it.
        $batch = FrozenPayoutGuard::batchFor($month);

        if ($batch !== null && ! FrozenPayoutGuard::isFrozen($batch)) {
            $summary = $this->payouts->unbuildBatch(
                $batch,
                $actorId,
                sprintf('Rebuilding the %s monthly close', $month->format('F Y')),
            );

            $removed['payout_line_items'] = $summary['line_items'];
            $removed['payout_batches'] = 1;
            // The batch's own debits are wallet rows too — counted here so the
            // wiped total matches the preview rather than the credits alone.
            $removed['wallet_ledger_entries'] = $summary['debits_deleted'] + $summary['forfeits_deleted'];
            $log(sprintf(
                '  %-28s batch #%d removed; %d credit(s) un-swept, %d debit(s) deleted',
                'payout_batches',
                $batch->id,
                $summary['entries_unswept'],
                $summary['debits_deleted'] + $summary['forfeits_deleted'],
            ));
        }

        $wiped = $this->db->connection()->transaction(function () use ($month): array {
            $grantIds = $this->ids($this->regrantableGrants($month)->lockForUpdate());

            return [
                'wallet_ledger_entries' => $this->deleteWalletEntries($month),
                'rank_aogo_grants' => $this->aogoGrants($month)->delete(),
                'rank_bonus_results' => $this->rankResults($month)->delete(),
                'rank_monthly_pools' => $this->rankPools($month)->delete(),
                'rank_qualifications' => $this->rankQualifications($month)->delete(),
                'lifetime_award_milestones' => $this->pendingMilestones($month)->delete(),
                'gbb_monthly_results' => $this->gbbResults($month)->delete(),
                'gbb_monthly_pools' => $this->gbbPools($month)->delete(),
                'fortune_bonus_results' => $this->fortuneResults($month)->delete(),
                // Children before parents: fortune_monthly_pool_levels carries a
                // foreign key onto the pool row.
                'fortune_monthly_pool_levels' => $this->fortunePoolLevels($month)->delete(),
                'fortune_monthly_pools' => $this->fortunePools($month)->delete(),
                'fortune_bonus_participants' => $this->fortuneParticipants($month)->delete(),
                'adc_bonus_results' => $this->adcResults($month)->delete(),
                // Read before the grants go: the entries are found by grant id.
                'redeem_point_entries' => $this->redeemEntriesFor($grantIds)->delete(),
                'purchase_offer_grants' => PurchaseOfferGrant::query()->whereIn('id', $grantIds)->delete(),
            ];
        });

        foreach ($wiped as $table => $count) {
            if ($count > 0) {
                $log(sprintf('  %-28s %d row(s) removed', $table, $count));
            }
        }

        // Added, not overwritten: both halves write `wallet_ledger_entries`.
        foreach ($removed as $table => $count) {
            $wiped[$table] = ($wiped[$table] ?? 0) + $count;
        }

        return new RebuildResult(
            array_filter($wiped, static fn (int $count): bool => $count > 0),
            [],
            $walletTotals,
        );
    }

    /** @return list<string> */
    private function refusals(Carbon $month, ?PayoutBatch $batch): array
    {
        $refusals = [];

        if (($open = OpenMonthGuard::refusal($month)) !== null) {
            $refusals[] = $open;
        }

        if ($batch !== null && $batch->status === PayoutBatch::STATUS_PROCESSING) {
            $refusals[] = $this->processingRefusal($batch);
        } elseif (($frozen = FrozenPayoutGuard::refusal($month)) !== null) {
            $refusals[] = $frozen;
        }

        $next = $month->copy()->addMonthNoOverflow();

        foreach ([...MonthlyEngineCompletionGate::ENGINE_KEYS, 'compensation.monthly-close'] as $key) {
            if ($this->status->hasSucceededRun($key, $next)) {
                $refusals[] = sprintf(
                    "%s was closed on %s's ranks, wallet verdicts and Fortune roster; %s can no longer be rebuilt "
                    .'underneath it (DN-5). The only remaining path is a correction decision by the client (R-91).',
                    $next->format('F Y'),
                    $month->format('F Y'),
                    $month->format('F Y'),
                );

                break;
            }
        }

        if (($swept = $this->sweptByOtherBatch($month, $batch)) !== null) {
            $refusals[] = $swept;
        }

        if (($spent = $this->spentRepurchaseCredit($month)) !== null) {
            $refusals[] = $spent;
        }

        if (($reversed = $this->reversedCredits($month)) > 0) {
            $refusals[] = sprintf(
                '%d of %s\'s credits were reversed by an admin decision; resolve those first.',
                $reversed,
                $month->format('F Y'),
            );
        }

        return $refusals;
    }

    /** @return list<string> */
    private function warnings(Carbon $month, ?PayoutBatch $batch): array
    {
        $warnings = [];

        if ($batch !== null && ! FrozenPayoutGuard::isFrozen($batch)) {
            $warnings[] = sprintf(
                "%s's payout batch #%d was removed with the stale credits. It is rebuilt by the monthly run from the "
                .'8th once every engine is green — or run `compensation:rebuild-payout --month=%s` '
                .'(`compensation:monthly-payout-close --month=%s` from a shell).',
                $month->format('F Y'),
                $batch->id,
                $month->format('Y-m'),
                $month->format('Y-m'),
            );
            $warnings[] = 'The rebuilt batch records you as its maker; a second person must approve it.';
        }

        $consumed = PurchaseOfferGrant::query()
            ->whereDate('month_start', $month->toDateString())
            ->whereNotNull('consumed_order_id')
            ->count();

        if ($consumed > 0) {
            $warnings[] = sprintf(
                '%d purchase-offer grant(s) already used on an order are kept and not re-derived (F124).',
                $consumed,
            );
        }

        $warnings[] = 'Repurchase cycle verdicts taken between the original close and now are not re-taken.';

        return $warnings;
    }

    private function processingRefusal(PayoutBatch $batch): string
    {
        return sprintf(
            'Batch #%d is stuck in processing — run `payout:reopen-stuck-batch --type=%s --date=%s --actor=<your user '
            .'id>` first, then rebuild.',
            $batch->id,
            $batch->batch_type,
            $batch->batch_date?->toDateString() ?? '',
        );
    }

    /**
     * Refuse when any of the month's credits are claimed by a batch this rebuild
     * is not going to un-build — frozen or not.
     *
     * "Frozen only" was the bug. The monthly sweep window is
     * `earnedForMonthOrBefore($month)`, so a batch for a LATER crediting month
     * legitimately collects this month's leftover credits (A7), while
     * `FrozenPayoutGuard::batchFor()` only ever finds the batch dated the 1st of
     * M+1. A later PENDING batch was therefore neither un-built nor refused, and
     * the wipe deleted credits its line items are priced on: approve it
     * afterwards and distributors are paid from line items with no backing
     * ledger rows, while the re-derived credits are swept and paid a second time
     * by a future batch. {@see NightRebuilder::sweptCredit()} has always applied
     * this rule; the asymmetry between the two was the defect.
     */
    private function sweptByOtherBatch(Carbon $month, ?PayoutBatch $unbuilding): ?string
    {
        // The batch the wipe takes apart itself — its stamps are removed by
        // unbuildBatch(), so they are not a reason to refuse. A frozen batch is
        // never un-built, so it is never excluded here.
        $exceptId = $unbuilding !== null && ! FrozenPayoutGuard::isFrozen($unbuilding) ? (int) $unbuilding->id : null;

        $count = 0;
        $batchId = null;

        foreach ($this->walletQueries($month) as $query) {
            $swept = (clone $query)
                ->whereNotNull('swept_by_payout_batch_id')
                ->when($exceptId !== null, fn (Builder $q): Builder => $q->where('swept_by_payout_batch_id', '!=', $exceptId));

            $count += (clone $swept)->count();
            $batchId ??= $swept->value('swept_by_payout_batch_id');
        }

        if ($count === 0) {
            return null;
        }

        $paidBy = $batchId === null ? null : PayoutBatch::query()->find((int) $batchId);

        return sprintf(
            "%d of %s's credits were paid by batch #%d (%s); money that left cannot be recomputed.",
            $count,
            $month->format('F Y'),
            (int) $batchId,
            $paidBy === null ? 'unknown status' : (string) $paidBy->status,
        );
    }

    /**
     * Refuse when the repurchase-wallet credits this wipe would delete have
     * since been drawn on. Same rule, same reason as
     * {@see NightRebuilder::spentRepurchaseCredit()}: a discount already taken
     * on an order is money that left, and the wallet balance floors at zero, so
     * an overspend would not show anywhere.
     */
    private function spentRepurchaseCredit(Carbon $month): ?string
    {
        $distributorIds = [];
        $since = null;

        foreach ($this->walletQueries($month) as $query) {
            $rows = (clone $query)->where('type', 'repurchase_deduction')
                ->toBase()
                ->get(['distributor_id', 'created_at']);

            foreach ($rows as $row) {
                $distributorIds[(int) $row->distributor_id] = true;
                $createdAt = $row->created_at === null ? null : Carbon::parse((string) $row->created_at);

                if ($createdAt !== null && ($since === null || $createdAt->lessThan($since))) {
                    $since = $createdAt;
                }
            }
        }

        if ($distributorIds === [] || $since === null) {
            return null;
        }

        $spent = 0;

        foreach (array_chunk(array_keys($distributorIds), self::CHUNK) as $chunk) {
            $spent += WalletLedgerEntry::query()
                ->where('type', 'repurchase_wallet_used')
                ->whereIn('distributor_id', $chunk)
                ->where('created_at', '>=', $since)
                ->count();
        }

        if ($spent === 0) {
            return null;
        }

        return sprintf(
            'The repurchase wallet has been drawn on %d time(s) since %s\'s credits were written; deleting those '
            .'credits would leave it short of what has already been spent on an order. Resolve those orders first.',
            $spent,
            $month->format('F Y'),
        );
    }

    /**
     * How much credited income the wipe removes, per wallet entry type — the
     * money behind the row count on the `compensation.rebuild.wiped` audit row.
     * The batch's own debits are recorded on `payout.batch.unbuilt` already.
     *
     * @return array<string, array{count: int, paise: int}>
     */
    private function walletTotals(Carbon $month): array
    {
        $totals = [];

        foreach ($this->walletQueries($month) as $query) {
            $rows = (clone $query)->toBase()
                ->selectRaw('type, COUNT(*) as row_count, SUM(amount_paise) as paise')
                ->groupBy('type')
                ->get();

            foreach ($rows as $row) {
                $type = (string) $row->type;
                $totals[$type] = [
                    'count' => ($totals[$type]['count'] ?? 0) + (int) $row->row_count,
                    'paise' => ($totals[$type]['paise'] ?? 0) + (int) $row->paise,
                ];
            }
        }

        return $totals;
    }

    private function reversedCredits(Carbon $month): int
    {
        $count = 0;

        foreach ($this->resultIds($month) as $referenceType => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $count += WalletLedgerEntry::query()
                    ->where('type', 'reversal')
                    ->where('reference_type', $referenceType)
                    ->whereIn('reference_id', $chunk)
                    ->count();
            }
        }

        return $count;
    }

    /**
     * The month's result-row ids, keyed by the `reference_type` the wallet rows
     * carry. plan(), the refusals and wipe() all read the month through this.
     *
     * @return array<string, list<int>>
     */
    private function resultIds(Carbon $month): array
    {
        return [
            'gbb_monthly_result' => $this->ids($this->gbbResults($month)),
            'rank_bonus_result' => $this->ids($this->rankResults($month)),
            'fortune_bonus_result' => $this->ids($this->fortuneResults($month)),
            'adc_bonus_result' => $this->ids($this->adcResults($month)),
        ];
    }

    /**
     * The wallet rows the month's results produced — by REFERENCE, never by
     * `created_at`: a monthly engine credits in arrears, so the rows are stamped
     * with the month AFTER the one they belong to.
     *
     * @return iterable<Builder<WalletLedgerEntry>>
     */
    private function walletQueries(Carbon $month): iterable
    {
        foreach ($this->resultIds($month) as $referenceType => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                yield WalletLedgerEntry::query()
                    ->where('reference_type', $referenceType)
                    ->whereIn('reference_id', $chunk)
                    ->whereIn('type', self::WALLET_TYPES);
            }
        }
    }

    private function countWalletEntries(Carbon $month): int
    {
        $count = 0;

        foreach ($this->walletQueries($month) as $query) {
            $count += $query->count();
        }

        return $count;
    }

    private function deleteWalletEntries(Carbon $month): int
    {
        $deleted = 0;

        foreach ($this->walletQueries($month) as $query) {
            $deleted += $query->delete();
        }

        return $deleted;
    }

    /**
     * The ids of everything a builder matches, as a list PHPStan can carry
     * through the `whereIn` chunks above.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            $query->pluck('id')->all(),
        ));
    }

    /** @return Builder<RankAogoGrant> */
    private function aogoGrants(Carbon $month): Builder
    {
        return RankAogoGrant::query()->whereDate('month_start', $month->toDateString());
    }

    /** @return Builder<RankBonusResult> */
    private function rankResults(Carbon $month): Builder
    {
        return RankBonusResult::query()->whereDate('month_start', $month->toDateString());
    }

    /** @return Builder<RankMonthlyPool> */
    private function rankPools(Carbon $month): Builder
    {
        return RankMonthlyPool::query()->whereDate('month_start', $month->toDateString());
    }

    /**
     * The month's own qualifications. A legacy `is_carry_forward` row was
     * written by an EARLIER month's check for this month, so it is that month's
     * output and this rebuild leaves it alone.
     *
     * @return Builder<RankQualification>
     */
    private function rankQualifications(Carbon $month): Builder
    {
        return RankQualification::query()
            ->whereDate('month_start', $month->toDateString())
            ->where('is_carry_forward', false);
    }

    /** @return Builder<LifetimeAwardMilestone> */
    private function pendingMilestones(Carbon $month): Builder
    {
        return LifetimeAwardMilestone::query()
            ->whereDate('triggered_month', $month->toDateString())
            ->where('status', LifetimeAwardMilestone::STATUS_PENDING);
    }

    /** @return Builder<GbbMonthlyResult> */
    private function gbbResults(Carbon $month): Builder
    {
        return GbbMonthlyResult::query()->whereDate('year_month', $month->toDateString());
    }

    /** @return Builder<GbbMonthlyPool> */
    private function gbbPools(Carbon $month): Builder
    {
        return GbbMonthlyPool::query()->whereDate('month_start', $month->toDateString());
    }

    /** @return Builder<FortuneBonusResult> */
    private function fortuneResults(Carbon $month): Builder
    {
        return FortuneBonusResult::query()->whereDate('month_start', $month->toDateString());
    }

    /** @return Builder<FortuneMonthlyPoolLevel> */
    private function fortunePoolLevels(Carbon $month): Builder
    {
        return FortuneMonthlyPoolLevel::query()->whereIn(
            'fortune_monthly_pool_id',
            FortuneMonthlyPool::query()->whereDate('month_start', $month->toDateString())->select('id'),
        );
    }

    /** @return Builder<FortuneMonthlyPool> */
    private function fortunePools(Carbon $month): Builder
    {
        return FortuneMonthlyPool::query()->whereDate('month_start', $month->toDateString());
    }

    /** @return Builder<FortuneBonusParticipant> */
    private function fortuneParticipants(Carbon $month): Builder
    {
        return FortuneBonusParticipant::query()->whereDate('month_start', $month->toDateString());
    }

    /** @return Builder<AdcBonusResult> */
    private function adcResults(Carbon $month): Builder
    {
        return AdcBonusResult::query()->whereDate('month_start', $month->toDateString());
    }

    /**
     * The month's purchase-offer grants a re-run may hand out again — a grant an
     * order has consumed stays (F124), and `alreadyGranted()` then skips it.
     *
     * @return Builder<PurchaseOfferGrant>
     */
    private function regrantableGrants(Carbon $month): Builder
    {
        return PurchaseOfferGrant::query()
            ->whereDate('month_start', $month->toDateString())
            ->whereNull('consumed_order_id');
    }

    /**
     * @param  list<int>  $grantIds
     * @return Builder<RedeemPointEntry>
     */
    private function redeemEntriesFor(array $grantIds): Builder
    {
        return RedeemPointEntry::query()
            ->where('reference_type', 'purchase_offer_grant')
            ->whereIn('reference_id', $grantIds);
    }
}
