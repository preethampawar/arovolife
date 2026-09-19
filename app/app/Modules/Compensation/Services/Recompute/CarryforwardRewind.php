<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Compensation\Models\GsbCutoffResult;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Restore `gsb_carryforward` to what it held before a range of cut-off days.
 *
 * The store is one row per distributor with no history of its own, so anything
 * that deletes cut-off rows has to put the carry-forward back or the next
 * cut-off compounds BV that has already been counted. The history is not lost:
 * every `gsb_cutoff_results` row records the `power_cf_before_paise` /
 * `power_side_before` / `slab1_weaker_cf_before_paise` it started from, so
 * restoring each distributor's EARLIEST in-range row's before-state IS the
 * rewind — read before the delete, applied after.
 *
 * Lifted out of {@see WindowedStateWiper} unchanged when the developer rebuild
 * arrived (ADR-0016): the windowed replay and `compensation:rebuild-night` face
 * the same rolling store and must rewind it the same way, and two copies of
 * this would be two chances for one of them to stop mirroring
 * `GsbCutoffService`'s null-side fallback.
 *
 * Unlike the rest of this namespace it is NOT testing-only: the night rebuild
 * runs it in production.
 */
final class CarryforwardRewind
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Each distributor's carry-forward as it stood at the start of the window:
     * the before-state recorded on their earliest in-window cut-off row.
     *
     * @return array<int, array{power: int, side: string|null, slab1: int}>
     */
    public function readFrom(Carbon $dayStart): array
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
            ->whereIn('status', GsbCutoffResult::CARRY_FORWARD_ADVANCING_STATUSES)
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
     * Why this rewind cannot be applied, or null when it can.
     *
     * {@see apply()} asks and throws, because by the time it runs the deletes
     * have already happened; the night rebuild asks while it is planning, so an
     * un-rewindable day is a refusal the operator reads in the preview rather
     * than a transaction that rolls back after they confirmed it. One predicate,
     * both readers.
     *
     * @param  array<int, array{power: int, side: string|null, slab1: int}>  $rewind
     */
    public function refusal(array $rewind): ?string
    {
        return $rewind === [] ? null : $this->orphanRefusal($rewind, $this->sides($rewind));
    }

    /**
     * @param  array<int, array{power: int, side: string|null, slab1: int}>  $rewind
     * @param  Closure(string): void  $log
     */
    public function apply(array $rewind, Closure $log): void
    {
        if ($rewind === []) {
            return;
        }

        $sides = $this->sides($rewind);

        if (($refusal = $this->orphanRefusal($rewind, $sides)) !== null) {
            throw new RuntimeException($refusal);
        }

        $now = Carbon::now();
        $legacy = 0;

        foreach ($rewind as $distributorId => $state) {
            $side = $sides[$distributorId];

            if ($state['side'] === null && $state['power'] > 0) {
                $legacy++;
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
     * The first carry forward that would be left without a side, phrased as a
     * refusal — or null when every one of them has one.
     *
     * @param  array<int, array{power: int, side: string|null, slab1: int}>  $rewind
     * @param  array<int, string|null>  $sides
     */
    private function orphanRefusal(array $rewind, array $sides): ?string
    {
        foreach ($sides as $distributorId => $side) {
            if ($side === null && $rewind[$distributorId]['power'] > 0) {
                return sprintf(
                    'Cannot rewind carry-forward for distributor %d: its earliest in-window '
                    .'cut-off predates the power_side_before column (2026-07-04) and the store '
                    .'has no side either, so a %d-paise carry forward would be orphaned. '
                    .'Run a full recompute instead of a windowed one for this date range.',
                    $distributorId,
                    $rewind[$distributorId]['power'],
                );
            }
        }

        return null;
    }

    /**
     * The side each rewound carry forward goes back on.
     *
     * `power_side_before` was added on 2026-07-04 without a backfill, so rows
     * written before then carry NULL. GsbCutoffService reads that column as
     * `$existing->power_side_before ?? $cfSide` — it falls back to the side
     * already in the store. Writing the raw NULL here instead would leave
     * `gsb_carryforward.power_side` null while the balance stayed non-zero, and
     * the next cut-off adds a null-sided balance to NEITHER leg: the carry
     * forward silently vanishes from the match. Mirror the engine's fallback
     * rather than the column.
     *
     * @param  array<int, array{power: int, side: string|null, slab1: int}>  $rewind
     * @return array<int, string|null>
     */
    private function sides(array $rewind): array
    {
        $stored = $this->db->table('gsb_carryforward')
            ->whereIn('distributor_id', array_keys($rewind))
            ->pluck('power_side', 'distributor_id');

        $sides = [];

        foreach ($rewind as $distributorId => $state) {
            $sides[$distributorId] = $state['side'] === null && $state['power'] > 0
                ? $stored[$distributorId] ?? null
                : $state['side'];
        }

        return $sides;
    }
}
