<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Support\DerivedTables;
use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use Closure;
use Illuminate\Database\DatabaseManager;
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
     * A monthly engine runs in ARREARS — GBB on the 2nd and the Rank Bonus on
     * the 8th, both for the month before — so July's bonus is credited to the
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

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Delete every derived row on or after $from and rewind the rolling stores.
     *
     * @param  Closure(string): void|null  $progress
     * @return array<string, int> table => rows removed
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
     * Rows that would be removed, without removing them — the confirmation
     * preview, mirroring CompensationStateWiper::preview().
     *
     * @return array<string, int>
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

        return $counts;
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
