<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Support\DerivedTables;
use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Removes only the derived rows a windowed replay is about to rebuild, and
 * rewinds the two rolling stores to what they held at the window's start.
 *
 * The sibling {@see CompensationStateWiper} truncates everything, which is why
 * a full recompute costs the same hour whatever you actually want to look at.
 * This one deletes from a date, so "what does this month pay?" replays this
 * month.
 *
 * Correctness rests on three things, in order of how badly they bite:
 *
 *  1. **Rolling state must be rewound, not kept.** gsb_carryforward is one row
 *     per distributor with no history, so a windowed replay that left it alone
 *     would compound the window's BV into a carry-forward that already contains
 *     it. The history is not lost, though: every gsb_cutoff_results row records
 *     the `power_cf_before_paise` / `power_side_before` /
 *     `slab1_weaker_cf_before_paise` it started from. Restoring each
 *     distributor's earliest in-window row's before-state IS the rewind — read
 *     before the delete, applied after.
 *  2. **Reversal debt is rewound arithmetically.** group_bv_debts has no date
 *     either: debt consumed by deleted credits comes back, debt created by
 *     deleted reversals goes away.
 *  3. **Monthly rows are deleted from their month's first day**, because a
 *     monthly engine's period is the month — half a month cannot be rebuilt.
 *     {@see CompensationRecomputeRunner} pairs this with the matching start-day
 *     rule so those months are actually replayed.
 *
 * TESTING ONLY, like everything else in this namespace.
 */
final class WindowedStateWiper
{
    /**
     * The row each kind of wallet entry was created from.
     *
     * A monthly engine runs in ARREARS — all monthly bonuses run on the 1st,
     * both for the month before — so July's bonus is credited to the
     * wallet in August. Deleting wallet entries purely by created_at therefore
     * removes credits whose source row sits safely before the window and is
     * never recomputed, and the re-run skips that period as already computed:
     * the money simply disappears. (Observed on the reference dataset: a window
     * opening 1 August lost 45 entries worth ₹20.1 lakh of GBB and Rank Bonus.)
     *
     * So a wallet entry is kept when the row that produced it survives the
     * wipe, and removed when that row is going to be rebuilt.
     *
     * @var array<string, string> reference_type => table holding the source row
     */
    private const WALLET_SOURCES = [
        'gsb_cutoff_result' => 'gsb_cutoff_results',
        'mentorship_bonus_result' => 'mentorship_bonus_results',
        'gbb_monthly_result' => 'gbb_monthly_results',
        'rank_bonus_result' => 'rank_bonus_results',
        'fortune_bonus_result' => 'fortune_bonus_results',
        'adc_bonus_result' => 'adc_bonus_results',
    ];

    /**
     * Reported alongside the deletions, under its own label: these rows are
     * reset in place rather than removed, and calling them `repurchase_cycles`
     * would read as "rows deleted" in the preview and the run summary.
     */
    private const CYCLE_RESET_KEY = 'repurchase_cycles (verdict reset)';

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Delete every derived row on or after $from and rewind the rolling stores.
     *
     * @param  Closure(string): void|null  $progress
     * @return array<string, int> display label => rows affected. Most labels are
     *                            table names; {@see self::CYCLE_RESET_KEY} is a
     *                            labelled reset, not a table, so never treat a
     *                            key here as a table name.
     */
    public function wipe(Carbon $from, ?Closure $progress = null): array
    {
        $log = $progress ?? static fn (string $_m): null => null;

        $dayStart = $from->copy()->startOfDay();
        $monthStart = $from->copy()->startOfMonth();

        // Read the rewind targets BEFORE the rows carrying them are deleted.
        $carryforwardRewind = $this->readCarryforwardRewind($dayStart);
        $debtRewind = $this->readDebtRewind($dayStart);

        $removed = [];

        Schema::disableForeignKeyConstraints();

        try {
            // Children whose parents are about to go, deleted by parent id:
            // neither table carries a date of its own.
            $removed['payout_line_items'] = $this->deleteByParent(
                'payout_line_items',
                'payout_batch_id',
                'payout_batches',
                'batch_date',
                $dayStart,
            );
            $removed['fortune_monthly_pool_levels'] = $this->deleteByParent(
                'fortune_monthly_pool_levels',
                'fortune_monthly_pool_id',
                'fortune_monthly_pools',
                'month_start',
                $monthStart,
            );

            // Wallet entries older than the window that a deleted batch had
            // already swept must become sweepable again — otherwise the
            // rebuilt payout skips money it is supposed to pay.
            $this->releaseSweptEntries($dayStart);

            foreach (DerivedTables::inTruncationOrder() as $table) {
                if (isset($removed[$table]) || ! $this->db->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                if ($table === 'engine_runs') {
                    $removed[$table] = $this->deleteEngineRuns($dayStart, $monthStart);

                    continue;
                }

                if ($table === 'wallet_ledger_entries') {
                    // Deferred to after every result table has been deleted, so
                    // "does the source row still exist?" can be answered.
                    continue;
                }

                $filter = DerivedTables::dateFilter($table);

                if ($filter === null) {
                    // gsb_carryforward / group_bv_debts — rewound below, never
                    // deleted: they hold state from before the window too.
                    continue;
                }

                $boundary = $filter['granularity'] === 'month' ? $monthStart : $dayStart;

                $removed[$table] = $this->db->table($table)
                    ->whereDate($filter['column'], '>=', $boundary->toDateString())
                    ->delete();
            }
            $removed['wallet_ledger_entries'] = $this->deleteOrphanedWalletEntries($dayStart);

            // Cycles are deleted by cycle_start_date, which leaves the ones that
            // STARTED before the window carrying a verdict computed from rows
            // that have just gone. Unresolve them so the replay judges them
            // again.
            $removed[self::CYCLE_RESET_KEY] = $this->resetCycleVerdictsInWindow($dayStart);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->applyCarryforwardRewind($carryforwardRewind, $log);
        $this->applyDebtRewind($debtRewind, $log);

        // Queued propagation jobs reference the pre-wipe state, exactly as in a
        // full wipe: a worker running one after the replay would double-apply.
        $queued = $this->db->table('jobs')
            ->where('payload', 'like', '%PropagateGroupBvJob%')
            ->delete();

        if ($queued > 0) {
            $log(sprintf('  %-28s %d queued job(s) removed', 'jobs', $queued));
        }

        foreach ($removed as $table => $count) {
            if ($count > 0) {
                $log(sprintf('  %-28s %d row(s)', $table, $count));
            }
        }

        return array_filter($removed, static fn (int $count): bool => $count > 0);
    }

    /**
     * Rows that would be removed or reset, without touching them — the
     * confirmation preview, mirroring CompensationStateWiper::preview().
     *
     * @return array<string, int> display label => rows affected, keyed as
     *                            {@see self::wipe()}: table names plus the
     *                            labelled cycle-verdict reset.
     */
    public function preview(Carbon $from): array
    {
        $dayStart = $from->copy()->startOfDay();
        $monthStart = $from->copy()->startOfMonth();
        $counts = [];

        foreach (DerivedTables::inTruncationOrder() as $table) {
            if (! $this->db->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $filter = DerivedTables::dateFilter($table);

            if ($filter === null) {
                continue;
            }

            $boundary = $filter['granularity'] === 'month' ? $monthStart : $dayStart;

            $count = (int) $this->db->table($table)
                ->whereDate($filter['column'], '>=', $boundary->toDateString())
                ->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        if ($this->db->getSchemaBuilder()->hasTable('repurchase_cycles')) {
            $reset = (int) $this->straddlingVerdicts($dayStart)->count()
                + (int) $this->lateFulfilmentsInWindow($dayStart)->count();

            if ($reset > 0) {
                $counts[self::CYCLE_RESET_KEY] = $reset;
            }
        }

        return $counts;
    }

    /**
     * Unresolve the cycles whose verdict was computed from rows this window has
     * just deleted, so the replay takes it again.
     *
     * A cycle is deleted only when its `cycle_start_date` falls inside the
     * window, but the verdict is taken at the window's END: a cycle that
     * started in July and was judged on 7 August keeps a `resolved_at` — and
     * `RepurchaseCycleService::resolveAtWindowEnd()` fires only while that is
     * null. So a windowed replay from 1 August would leave August's verdict
     * standing on wallet ledger rows and BV credits that no longer exist,
     * silently, and every forfeited day it implies with it.
     *
     * Two shapes:
     *
     *  1. **Judged inside the window** (`cycle_start < from <= due`) — the whole
     *     verdict goes: it is re-taken from the rebuilt ledger.
     *  2. **Judged before the window, fulfilled late inside it**
     *     (`due < from <= fulfilled_on`) — the verdict itself stands, because it
     *     was computed from surviving rows; only the fulfilment is undone. It
     *     matters beyond this cycle: the fulfilment day re-anchors the next
     *     window, so a stale one shifts every later cycle.
     *
     * Idempotent: shape 1 rewrites the same nulls, and shape 2 no longer
     * matches once `fulfilled_on` is null.
     */
    public function resetCycleVerdictsInWindow(Carbon $from): int
    {
        if (! $this->db->getSchemaBuilder()->hasTable('repurchase_cycles')) {
            return 0;
        }

        $dayStart = $from->copy()->startOfDay();
        $now = Carbon::now();

        $reset = $this->straddlingVerdicts($dayStart)->update([
            'resolved_at' => null,
            'wallet_balance_paise' => null,
            'wallet_zeroed' => null,
            'fulfilled_on' => null,
            'failure_reason' => null,
            'status' => RepurchaseCycle::STATUS_ACTIVE,
            'completed_bv_paise' => 0,
            'completed_at' => null,
            'updated_at' => $now,
        ]);

        $reset += $this->lateFulfilmentsInWindow($dayStart)->update([
            'fulfilled_on' => null,
            // Back to what the verdict said: the window closed unmet and the
            // fulfilment that ended the forfeit has not been rebuilt yet.
            'status' => RepurchaseCycle::STATUS_SUSPENDED,
            'completed_at' => null,
            'updated_at' => $now,
        ]);

        return $reset;
    }

    /** Cycles that started before the window and are judged inside it. */
    private function straddlingVerdicts(Carbon $dayStart): QueryBuilder
    {
        return $this->db->table('repurchase_cycles')
            ->whereDate('cycle_start_date', '<', $dayStart->toDateString())
            ->whereDate('due_date', '>=', $dayStart->toDateString());
    }

    /** Cycles judged before the window whose late fulfilment falls inside it. */
    private function lateFulfilmentsInWindow(Carbon $dayStart): QueryBuilder
    {
        return $this->db->table('repurchase_cycles')
            ->whereDate('cycle_start_date', '<', $dayStart->toDateString())
            ->whereDate('due_date', '<', $dayStart->toDateString())
            ->whereNotNull('fulfilled_on')
            ->whereDate('fulfilled_on', '>=', $dayStart->toDateString());
    }

    /**
     * Each distributor's carry-forward as it stood at the start of the window:
     * the before-state recorded on their earliest in-window cut-off row.
     *
     * @return array<int, array{power: int, side: string|null, slab1: int}>
     */
    private function readCarryforwardRewind(Carbon $dayStart): array
    {
        $rows = $this->db->table('gsb_cutoff_results')
            ->whereDate('cutoff_date', '>=', $dayStart->toDateString())
            // Only rows that actually moved the carry-forward carry a
            // meaningful before-state. A `below_600bv` row records zeros
            // because the engine returns before it ever reads the store —
            // rewinding from one would invent an all-zero carry-forward row for
            // every distributor who has never purchased (126 of 288 on the
            // reference dataset, none of which a full replay creates).
            // `repurchase_forfeited` is absent for the same reason: the client's
            // 2026-09-07 forfeit deliberately leaves both stores untouched.
            //
            // PARITY PARTNER: this list must stay identical to
            // GsbCutoffResult::advancedCarryForward(). GsbCutoffServiceTest
            // reads both method bodies and pins them equal.
            ->whereIn('status', [
                GsbCutoffResult::STATUS_NO_MATCH,
                GsbCutoffResult::STATUS_FROZEN,
                GsbCutoffResult::STATUS_REPURCHASE_HELD,
                GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED,
                GsbCutoffResult::STATUS_CREDITED,
                GsbCutoffResult::STATUS_REVERSED,
            ])
            ->orderBy('distributor_id')
            ->orderBy('cutoff_date')
            ->orderBy('id')
            ->get(['distributor_id', 'cutoff_date', 'power_cf_before_paise', 'power_side_before', 'slab1_weaker_cf_before_paise']);

        $rewind = [];

        foreach ($rows as $row) {
            $id = (int) $row->distributor_id;

            // Ordered ascending, so the first row seen per distributor is the
            // earliest in the window — the state to rewind to.
            if (isset($rewind[$id])) {
                continue;
            }

            $rewind[$id] = [
                'power' => (int) $row->power_cf_before_paise,
                'side' => $row->power_side_before,
                'slab1' => (int) $row->slab1_weaker_cf_before_paise,
            ];
        }

        return $rewind;
    }

    /**
     * Reversal debt as it stood at the window's start, per (distributor, side):
     * whatever it is now, plus the debt the deleted credits paid down, minus
     * the debt the deleted reversals created.
     *
     * @return array<string, int> "distributorId|side" => paise
     */
    private function readDebtRewind(Carbon $dayStart): array
    {
        $delta = [];

        $consumed = $this->db->table('group_bv_credits')
            ->whereDate('date', '>=', $dayStart->toDateString())
            ->where('debt_consumed_paise', '>', 0)
            ->selectRaw('ancestor_id, side, SUM(debt_consumed_paise) AS total')
            ->groupBy('ancestor_id', 'side')
            ->get();

        foreach ($consumed as $row) {
            $delta[$row->ancestor_id.'|'.$row->side] = ($delta[$row->ancestor_id.'|'.$row->side] ?? 0) + (int) $row->total;
        }

        $created = $this->db->table('group_bv_reversals')
            ->whereDate('date', '>=', $dayStart->toDateString())
            ->where('debt_paise', '>', 0)
            ->selectRaw('ancestor_id, side, SUM(debt_paise) AS total')
            ->groupBy('ancestor_id', 'side')
            ->get();

        foreach ($created as $row) {
            $delta[$row->ancestor_id.'|'.$row->side] = ($delta[$row->ancestor_id.'|'.$row->side] ?? 0) - (int) $row->total;
        }

        return $delta;
    }

    /**
     * @param  array<int, array{power: int, side: string|null, slab1: int}>  $rewind
     * @param  Closure(string): void  $log
     */
    private function applyCarryforwardRewind(array $rewind, Closure $log): void
    {
        if ($rewind === []) {
            return;
        }

        $now = Carbon::now();

        // `power_side_before` was added on 2026-07-04 without a backfill, so
        // rows written before then carry NULL. GsbCutoffService reads that
        // column as `$existing->power_side_before ?? $cfSide` — it falls back to
        // the side already in the store. Writing the raw NULL here instead would
        // leave `gsb_carryforward.power_side` null while the balance stayed
        // non-zero, and the next cut-off adds a null-sided balance to NEITHER
        // leg: the carry forward silently vanishes from the match. Mirror the
        // engine's fallback rather than the column.
        $existingSides = $this->db->table('gsb_carryforward')
            ->whereIn('distributor_id', array_keys($rewind))
            ->pluck('power_side', 'distributor_id');

        $legacy = 0;

        foreach ($rewind as $distributorId => $state) {
            $side = $state['side'];

            if ($side === null && $state['power'] > 0) {
                $side = $existingSides[$distributorId] ?? null;
                $legacy++;

                if ($side === null) {
                    throw new RuntimeException(sprintf(
                        'Cannot rewind carry-forward for distributor %d: its earliest in-window '
                        .'cut-off predates the power_side_before column (2026-07-04) and the store '
                        .'has no side either, so a %d-paise carry forward would be orphaned. '
                        .'Run a full recompute instead of a windowed one for this date range.',
                        $distributorId,
                        $state['power'],
                    ));
                }
            }

            $this->db->table('gsb_carryforward')->updateOrInsert(
                ['distributor_id' => $distributorId],
                [
                    'power_side_bv_paise' => $state['power'],
                    'power_side' => $side,
                    'slab1_weaker_bv_paise' => $state['slab1'],
                    'updated_at' => $now,
                ],
            );
        }

        if ($legacy > 0) {
            $log(sprintf(
                '  %-28s %d distributor(s) had no power_side_before (pre-2026-07-04); kept the stored side',
                'gsb_carryforward',
                $legacy,
            ));
        }

        $log(sprintf('  %-28s %d distributor(s) rewound', 'gsb_carryforward', count($rewind)));
    }

    /**
     * @param  array<string, int>  $delta
     * @param  Closure(string): void  $log
     */
    private function applyDebtRewind(array $delta, Closure $log): void
    {
        $touched = 0;

        foreach ($delta as $key => $paise) {
            if ($paise === 0) {
                continue;
            }

            [$distributorId, $side] = explode('|', $key);

            $existing = $this->db->table('group_bv_debts')
                ->where('distributor_id', (int) $distributorId)
                ->where('side', $side)
                ->first();

            $target = max(0, (int) ($existing->bv_paise ?? 0) + $paise);

            if ($existing === null) {
                if ($target === 0) {
                    continue;
                }

                $this->db->table('group_bv_debts')->insert([
                    'distributor_id' => (int) $distributorId,
                    'side' => $side,
                    'bv_paise' => $target,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            } else {
                $this->db->table('group_bv_debts')
                    ->where('id', $existing->id)
                    ->update(['bv_paise' => $target, 'updated_at' => Carbon::now()]);
            }

            $touched++;
        }

        if ($touched > 0) {
            $log(sprintf('  %-28s %d debt row(s) rewound', 'group_bv_debts', $touched));
        }
    }

    /** Delete a child table by the ids of the parents this window removes. */
    private function deleteByParent(
        string $childTable,
        string $foreignKey,
        string $parentTable,
        string $parentDateColumn,
        Carbon $boundary,
    ): int {
        if (! $this->db->getSchemaBuilder()->hasTable($childTable)) {
            return 0;
        }

        $parentIds = $this->db->table($parentTable)
            ->whereDate($parentDateColumn, '>=', $boundary->toDateString())
            ->pluck('id');

        if ($parentIds->isEmpty()) {
            return 0;
        }

        return $this->db->table($childTable)->whereIn($foreignKey, $parentIds)->delete();
    }

    /**
     * Remove the wallet entries the window is going to recreate — and only
     * those. An entry created inside the window survives when the result row it
     * references still exists (a monthly bonus for a month before the window,
     * credited during it); it goes when that row was deleted and will be
     * rebuilt, and when it references nothing identifiable at all.
     */
    private function deleteOrphanedWalletEntries(Carbon $dayStart): int
    {
        $deleted = 0;

        // Anything whose reference we cannot resolve is rebuilt from scratch.
        $deleted += $this->db->table('wallet_ledger_entries')
            ->whereDate('created_at', '>=', $dayStart->toDateString())
            ->where(function ($query): void {
                $query->whereNull('reference_type')
                    ->orWhereNull('reference_id')
                    ->orWhereNotIn('reference_type', array_keys(self::WALLET_SOURCES));
            })
            ->delete();

        foreach (self::WALLET_SOURCES as $referenceType => $sourceTable) {
            if (! $this->db->getSchemaBuilder()->hasTable($sourceTable)) {
                continue;
            }

            $deleted += $this->db->table('wallet_ledger_entries')
                ->whereDate('created_at', '>=', $dayStart->toDateString())
                ->where('reference_type', $referenceType)
                ->whereNotIn('reference_id', $this->db->table($sourceTable)->select('id'))
                ->delete();
        }

        return $deleted;
    }

    /**
     * A wallet entry older than the window keeps its sweep marker only while
     * the batch that swept it survives.
     */
    private function releaseSweptEntries(Carbon $dayStart): void
    {
        $batchIds = $this->db->table('payout_batches')
            ->whereDate('batch_date', '>=', $dayStart->toDateString())
            ->pluck('id');

        if ($batchIds->isEmpty()) {
            return;
        }

        $this->db->table('wallet_ledger_entries')
            ->whereIn('swept_by_payout_batch_id', $batchIds)
            ->update(['swept_by_payout_batch_id' => null]);
    }

    /**
     * The run log follows the results it describes: daily runs from the window
     * start, monthly runs from the month start — otherwise a surviving July run
     * would tell EngineStatusService that a month whose rows were just deleted
     * is still computed, and the replay would skip rebuilding it.
     */
    private function deleteEngineRuns(Carbon $dayStart, Carbon $monthStart): int
    {
        $monthlyKeys = [];

        foreach (EngineRegistry::all() as $definition) {
            if ($definition->periodType === EnginePeriodType::Month) {
                $monthlyKeys[] = $definition->key;
            }
        }

        return $this->db->table('engine_runs')
            ->where(function ($query) use ($dayStart, $monthlyKeys, $monthStart): void {
                $query->whereDate('period_start', '>=', $dayStart->toDateString());

                if ($monthlyKeys !== []) {
                    $query->orWhere(function ($inner) use ($monthlyKeys, $monthStart): void {
                        $inner->whereIn('engine_key', $monthlyKeys)
                            ->whereDate('period_start', '>=', $monthStart->toDateString());
                    });
                }
            })
            ->delete();
    }
}
