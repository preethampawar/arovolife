<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Rebuild;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Compensation\Models\GsbPersonalBvTopup;
use App\Modules\Compensation\Models\GsbReversalRequest;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\Recompute\CarryforwardRewind;
use App\Modules\Compensation\Support\FrozenPayoutGuard;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Rebuild one night N: delete everything the cut-off for day D = N−1 derived,
 * rewind the rolling carry-forward to what that day started from, and let the
 * ordinary nightly run compute it again.
 *
 * ONLY WHILE N IS THE NEWEST NIGHT (D11, R-91). `gsb_carryforward` holds one
 * state per distributor and no history: each night reads it, folds the day's
 * group BV in and writes it forward. Once any later day has been cut off — an
 * idle `no_match` row counts, it was still written from the store — the state
 * this day would have to start from is gone, and rewinding to it would erase
 * the later day. So the window is same-day and the deadline is the next nightly
 * run at 00:05 IST; past that the remedy is a windowed recompute, which runs on
 * dev and staging only.
 *
 * Nothing here writes a credit by hand. The wipe removes rows the re-run
 * re-derives through the engines' ordinary product-sale-chained path (D10), and
 * a credit that has been PAID makes it refuse outright rather than recompute
 * money that has left.
 */
final class NightRebuilder
{
    /**
     * The wallet rows one night's engines write against a result row: the gross
     * credit, and the two halves of the credit-time repurchase deduction.
     *
     * A `reversal` debit is deliberately absent — a reversed day is refused, not
     * rebuilt — and so is its `*_reversal`-suffixed repurchase row, which the
     * reference_type equality below never matches.
     *
     * @var list<string>
     */
    private const WALLET_TYPES = ['gsb_credit', 'mb_credit', 'repurchase_transfer', 'repurchase_deduction'];

    /** How many ids go into one `whereIn`. */
    private const CHUNK = 5_000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly EngineStatusService $status,
        private readonly CarryforwardRewind $rewind,
    ) {}

    public function plan(Carbon $night): RebuildPlan
    {
        $night = $night->copy()->startOfDay();
        $day = $night->copy()->subDay();

        $resultIds = $this->ids($this->cutoffResults($day));
        $mentorshipIds = $this->ids($this->mentorshipResults($day));

        $rows = [
            'gsb_cutoff_results' => count($resultIds),
            'mentorship_bonus_results' => count($mentorshipIds),
            'gsb_daily_pools' => $this->dailyPools($day)->count(),
            'msb_daily_pools' => $this->msbPools($day)->count(),
            'gsb_personal_bv_topups' => $this->personalBvTopups($day)->count(),
            'wallet_ledger_entries' => $this->countWalletEntries($resultIds, $mentorshipIds),
        ];

        return new RebuildPlan(
            RebuildKind::Night,
            $night,
            $this->refusals($night, $day, $resultIds, $mentorshipIds),
            $this->warnings($night, $day),
            array_filter($rows, static fn (int $count): bool => $count > 0),
            0,
            array_filter(['group_bv_daily' => count($this->topupsToReturn($day))], static fn (int $count): bool => $count > 0),
        );
    }

    /**
     * @param  Closure(string): void  $log
     * @param  Closure(): void  $verify  Re-asks the refusals under the row locks; throws if anything moved.
     */
    public function wipe(Carbon $night, Closure $log, Closure $verify): RebuildResult
    {
        $day = $night->copy()->startOfDay()->subDay();

        // Read the rewind targets BEFORE the rows carrying them are deleted. An
        // un-rewindable day is already a refusal in plan(); reaching one now
        // means the state changed under the confirm, and apply() still throws
        // inside the transaction rather than leaving half a night behind.
        $rewind = $this->rewind->readFrom($day);

        return $this->db->connection()->transaction(function () use ($day, $rewind, $log, $verify): RebuildResult {
            $resultIds = $this->ids($this->cutoffResults($day)->lockForUpdate());
            $mentorshipIds = $this->ids($this->mentorshipResults($day)->lockForUpdate());

            // D11 re-asked here, inside the transaction and before the first
            // delete, because the preview and the confirm are two moments: a
            // nightly run that cut the next day off while the operator was
            // reading would leave this wipe rewinding the store under a day
            // already built on it, and every retry after that is out of order.
            $verify();

            $walletTotals = $this->walletTotals($resultIds, $mentorshipIds);
            $returned = $this->returnTopupBv($day);

            $removed = [
                'wallet_ledger_entries' => $this->deleteWalletEntries($resultIds, $mentorshipIds),
                'mentorship_bonus_results' => $this->mentorshipResults($day)->delete(),
                'gsb_cutoff_results' => $this->cutoffResults($day)->delete(),
                'gsb_daily_pools' => $this->dailyPools($day)->delete(),
                'msb_daily_pools' => $this->msbPools($day)->delete(),
                'gsb_personal_bv_topups' => $this->personalBvTopups($day)->delete(),
            ];

            $this->rewind->apply($rewind, $log);

            foreach ($removed as $table => $count) {
                if ($count > 0) {
                    $log(sprintf('  %-28s %d row(s) removed', $table, $count));
                }
            }

            if ($returned['rows'] > 0) {
                $log(sprintf(
                    '  %-28s %d accumulator row(s) reduced; %d paise of personal-BV top-up handed back',
                    'group_bv_daily',
                    $returned['rows'],
                    $returned['paise'],
                ));
            }

            return new RebuildResult(
                array_filter($removed, static fn (int $count): bool => $count > 0),
                array_filter(['group_bv_daily' => $returned['rows']], static fn (int $count): bool => $count > 0),
                $walletTotals,
            );
        });
    }

    /**
     * @param  list<int>  $resultIds
     * @param  list<int>  $mentorshipIds
     * @return list<string>
     */
    private function refusals(Carbon $night, Carbon $day, array $resultIds, array $mentorshipIds): array
    {
        $refusals = [];

        if ($night->greaterThan(Carbon::today())) {
            return [sprintf('Cannot rebuild %s: that night has not arrived.', $night->toDateString())];
        }

        if (($newest = $this->newestNightPast($night, $day)) !== null) {
            $refusals[] = sprintf(
                'Cannot rebuild the %s cut-off: %s has already been cut off and the carry-forward store has moved '
                .'past %s (R-91). A day can be rebuilt only while it is the newest one; the deadline is the next '
                .'nightly run at 00:05 IST. Replaying history from %s forward is a windowed recompute, which runs on '
                .'dev and staging only.',
                $day->toDateString(),
                $newest,
                $day->toDateString(),
                $day->toDateString(),
            );
        }

        if (($swept = $this->sweptCredit($resultIds, $mentorshipIds)) !== null) {
            $refusals[] = $swept;
        }

        if (($reversed = $this->reversedResults($day)) !== null) {
            $refusals[] = $reversed;
        }

        if (($spent = $this->spentRepurchaseCredit($resultIds, $mentorshipIds, $day->toDateString())) !== null) {
            $refusals[] = $spent;
        }

        $month = $day->copy()->startOfMonth();

        if ($this->status->hasSucceededRun('compensation.monthly-close', $month)
            && FrozenPayoutGuard::frozenBatchFor($month) !== null) {
            $refusals[] = sprintf(
                '%s was closed and its payout is frozen; %s is inside it. %s',
                $month->format('F Y'),
                $day->toDateString(),
                FrozenPayoutGuard::refusal($month) ?? '',
            );
        }

        // A carry forward the rewind cannot give a side back to is a refusal
        // here, before the confirm, rather than a transaction that rolls back
        // after it: `apply()` throws on the same predicate inside the wipe.
        if (($orphan = $this->rewind->refusal($this->rewind->readFrom($day))) !== null) {
            $refusals[] = $orphan;
        }

        return $refusals;
    }

    /**
     * The newest thing that has already moved past this night, or null while it
     * is still the newest.
     *
     * Two signals, because either one means the rolling state has advanced: a
     * cut-off row for a later day (any status — an idle `no_match` row was still
     * written from the store), and a succeeded repurchase evaluation dated after
     * this night, whose verdicts a later cut-off has already priced.
     */
    private function newestNightPast(Carbon $night, Carbon $day): ?string
    {
        $laterCutoff = GsbCutoffResult::query()
            ->whereDate('cutoff_date', '>', $day->toDateString())
            ->orderByDesc('cutoff_date')
            ->value('cutoff_date');

        if ($laterCutoff !== null) {
            return Carbon::parse((string) $laterCutoff)->toDateString();
        }

        $laterEvaluate = EngineRun::query()
            ->where('engine_key', 'repurchase.evaluate')
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->whereDate('period_start', '>', $night->toDateString())
            ->orderByDesc('period_start')
            ->value('period_start');

        return $laterEvaluate === null ? null : Carbon::parse((string) $laterEvaluate)->subDay()->toDateString();
    }

    /**
     * @param  list<int>  $resultIds
     * @param  list<int>  $mentorshipIds
     */
    private function sweptCredit(array $resultIds, array $mentorshipIds): ?string
    {
        $count = 0;
        $batch = null;

        foreach ($this->walletQueries($resultIds, $mentorshipIds) as $query) {
            $swept = (clone $query)->whereNotNull('swept_by_payout_batch_id');
            $count += $swept->count();
            $batch ??= $swept->value('swept_by_payout_batch_id');
        }

        if ($count === 0) {
            return null;
        }

        $batchId = $batch === null ? 0 : (int) $batch;
        $paidBy = $batchId === 0 ? null : PayoutBatch::query()->find($batchId);

        return sprintf(
            "%d of the day's credits were paid by batch #%d (%s); money that left cannot be recomputed.",
            $count,
            $batchId,
            $paidBy === null ? 'unknown status' : (string) $paidBy->status,
        );
    }

    private function reversedResults(Carbon $day): ?string
    {
        // Widened from the plan's "pending request" on purpose, and NOT because
        // of the foreign key: `gsb_reversal_requests.gsb_cutoff_result_id` is
        // `nullOnDelete`, so a decided request would survive the delete with a
        // dangling null rather than block it. The reason is the decision itself
        // — a day anyone has ever asked to reverse is a day somebody has ruled
        // on, and it is not recomputed silently underneath that ruling.
        $reversed = $this->cutoffResults($day)->where('status', GsbCutoffResult::STATUS_REVERSED)->count();
        $requested = GsbReversalRequest::query()->whereDate('cutoff_date', $day->toDateString())->count();

        if ($reversed === 0 && $requested === 0) {
            return null;
        }

        return sprintf(
            "%d of the day's credits were reversed by an admin decision, or have a reversal request on file; "
            .'resolve those first.',
            max($reversed, $requested),
        );
    }

    /** @return list<string> */
    private function warnings(Carbon $night, Carbon $day): array
    {
        $warnings = [];
        $month = $day->copy()->startOfMonth();

        if ($night->isTuesday()
            && ! $this->status->payoutBatchExists(PayoutBatch::TYPE_WEEKLY, $night)) {
            $warnings[] = sprintf(
                'The weekly run for %s (Tuesday) was deferred: run `php artisan compensation:weekly-run --date=%s` '
                .'after this succeeds, or let 03:00 tomorrow build it.',
                $night->toDateString(),
                $night->toDateString(),
            );
        }

        $closed = $this->status->hasSucceededRun('compensation.monthly-close', $month);

        if ($night->day === 1 && ! $closed) {
            $warnings[] = sprintf(
                'The monthly close for %s was deferred: run `php artisan compensation:monthly-run --date=%s` after '
                .'this succeeds, or let 04:00 tomorrow close it.',
                $month->format('F Y'),
                $night->toDateString(),
            );
        }

        if ($closed && FrozenPayoutGuard::frozenBatchFor($month) === null) {
            $warnings[] = sprintf(
                "%s was closed on %s's earlier figures. Rebuild the month next: "
                .'`compensation:rebuild-month --month=%s`.',
                $month->format('F Y'),
                $day->toDateString(),
                $month->format('Y-m'),
            );
        }

        return $warnings;
    }

    /**
     * The ids of everything a builder matches, as a list PHPStan can carry
     * through the `whereIn` chunks below.
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

    /** @return Builder<GsbCutoffResult> */
    private function cutoffResults(Carbon $day): Builder
    {
        return GsbCutoffResult::query()->whereDate('cutoff_date', $day->toDateString());
    }

    /** @return Builder<MentorshipBonusResult> */
    private function mentorshipResults(Carbon $day): Builder
    {
        return MentorshipBonusResult::query()->whereDate('cutoff_date', $day->toDateString());
    }

    /** @return Builder<GsbDailyPool> */
    private function dailyPools(Carbon $day): Builder
    {
        return GsbDailyPool::query()->whereDate('cutoff_date', $day->toDateString());
    }

    /** @return Builder<MsbDailyPool> */
    private function msbPools(Carbon $day): Builder
    {
        return MsbDailyPool::query()->whereDate('cutoff_date', $day->toDateString());
    }

    /** @return Builder<GsbPersonalBvTopup> */
    private function personalBvTopups(Carbon $day): Builder
    {
        return GsbPersonalBvTopup::query()->whereDate('date', $day->toDateString());
    }

    /**
     * The wallet rows the day's two result tables produced, chunked.
     *
     * By REFERENCE, never by `created_at`: a night re-run days later writes rows
     * stamped with that day, and a credit's source row is the only thing that
     * says which period it belongs to. plan() counts these and wipe() deletes
     * them through this one generator, so the preview and the deletion can never
     * describe different rows.
     *
     * @param  list<int>  $resultIds
     * @param  list<int>  $mentorshipIds
     * @return iterable<Builder<WalletLedgerEntry>>
     */
    private function walletQueries(array $resultIds, array $mentorshipIds): iterable
    {
        foreach (['gsb_cutoff_result' => $resultIds, 'mentorship_bonus_result' => $mentorshipIds] as $referenceType => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                yield WalletLedgerEntry::query()
                    ->where('reference_type', $referenceType)
                    ->whereIn('reference_id', $chunk)
                    ->whereIn('type', self::WALLET_TYPES);
            }
        }
    }

    /**
     * @param  list<int>  $resultIds
     * @param  list<int>  $mentorshipIds
     */
    private function countWalletEntries(array $resultIds, array $mentorshipIds): int
    {
        $count = 0;

        foreach ($this->walletQueries($resultIds, $mentorshipIds) as $query) {
            $count += $query->count();
        }

        return $count;
    }

    /**
     * @param  list<int>  $resultIds
     * @param  list<int>  $mentorshipIds
     */
    private function deleteWalletEntries(array $resultIds, array $mentorshipIds): int
    {
        $deleted = 0;

        foreach ($this->walletQueries($resultIds, $mentorshipIds) as $query) {
            $deleted += $query->delete();
        }

        return $deleted;
    }

    /**
     * How much credited income the wipe removes, per wallet entry type.
     *
     * Counted before the delete and recorded on the audit row: a period wiped
     * and then never rebuilt (the re-run failed and nobody came back) otherwise
     * leaves a row count and no money, and the question asked afterwards is
     * always how much.
     *
     * @param  list<int>  $resultIds
     * @param  list<int>  $mentorshipIds
     * @return array<string, array{count: int, paise: int}>
     */
    private function walletTotals(array $resultIds, array $mentorshipIds): array
    {
        $totals = [];

        foreach ($this->walletQueries($resultIds, $mentorshipIds) as $query) {
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

    /**
     * The day's un-reversed personal-BV top-ups, summed per distributor and side.
     *
     * A top-up row is not a ledger entry that can simply be deleted: writing it
     * also INCREMENTED `group_bv_daily.<weaker side>_bv_paise` for the day
     * ({@see GsbPersonalBvTopupService::applyPendingForDistributor()}). Deleting
     * the row alone makes the order pending again while its BV stays in the
     * accumulator, and the re-run then mirrors the same top-up on top of an
     * already-inflated leg — a second, permanent increment that the Rank check
     * reads as Genos BV too. Already-reversed rows are skipped: the reversal
     * decremented them at the time.
     *
     * @return list<array{distributor_id: int, side: string, bv_paise: int}>
     */
    private function topupsToReturn(Carbon $day): array
    {
        $rows = $this->personalBvTopups($day)
            ->whereNull('reversed_at')
            ->toBase()
            ->selectRaw('distributor_id, side, SUM(bv_paise) as bv_paise')
            ->groupBy('distributor_id', 'side')
            ->orderBy('distributor_id')
            ->get();

        return array_values($rows->map(static fn (object $row): array => [
            'distributor_id' => (int) $row->distributor_id,
            'side' => (string) $row->side,
            'bv_paise' => (int) $row->bv_paise,
        ])->all());
    }

    /**
     * Hand the day's personal-BV top-ups back to the accumulators they were
     * added to, mirroring the unsettled branch of
     * {@see GsbPersonalBvTopupService::reverseForOrder()} — the same lock, the
     * same column, the same date.
     *
     * `group_bv_daily` is source data everywhere else in this wipe (§29), and it
     * stays so: the only figure touched is the one the top-up itself put there.
     *
     * @return array{rows: int, paise: int}
     */
    private function returnTopupBv(Carbon $day): array
    {
        $connection = $this->db->connection();
        $dateString = $day->toDateString();
        $rows = 0;
        $paise = 0;

        foreach ($this->topupsToReturn($day) as $topup) {
            $column = $topup['side'] === 'L' ? 'left_bv_paise' : 'right_bv_paise';

            $connection->table('group_bv_daily')
                ->where('distributor_id', $topup['distributor_id'])
                ->whereDate('date', $dateString)
                ->lockForUpdate()
                ->first();

            $affected = $connection->table('group_bv_daily')
                ->where('distributor_id', $topup['distributor_id'])
                ->whereDate('date', $dateString)
                ->decrement($column, $topup['bv_paise']);

            if ($affected > 0) {
                $rows += $affected;
                $paise += $topup['bv_paise'];
            }
        }

        return ['rows' => $rows, 'paise' => $paise];
    }

    /**
     * Refuse when the repurchase-wallet credits this wipe would delete have
     * since been drawn on.
     *
     * A `repurchase_deduction` row is not only a record: it is the balance
     * itself, and an order can spend against it the same day. Deleting the
     * credit while the `repurchase_wallet_used` debit stays leaves a real
     * position below zero, which {@see WalletService::repurchaseWalletBalancePaise()}
     * floors at 0 and therefore hides — and the "repurchase wallet must be zero"
     * gates would then read a wallet that goes non-zero again the moment any
     * credit lands. A discount taken on an order is money that left; the
     * rebuild stops rather than papering over it.
     *
     * @param  list<int>  $resultIds
     * @param  list<int>  $mentorshipIds
     */
    private function spentRepurchaseCredit(array $resultIds, array $mentorshipIds, string $period): ?string
    {
        $distributorIds = [];
        $since = null;

        foreach ($this->walletQueries($resultIds, $mentorshipIds) as $query) {
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
            $period,
        );
    }
}
