<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-writes the cut-off rows for distributors who cannot possibly match a
 * slab on a given date, so the per-distributor engine never runs for them.
 *
 * Why this exists: the nightly cut-off is O(distributors × days) but its
 * outcome is O(distributors with business). On the reference dataset a full
 * replay wrote 13,248 rows to produce 187 credits — 5,796 of them for 126
 * distributors who have never purchased anything. Each of those rows cost a
 * firstOrCreate + update + insert through Eloquent; here they cost one batched
 * INSERT per day.
 *
 * This is a FILTER, never a second implementation of the matching rules. A
 * distributor qualifies only when every input the engine would read is provably
 * inert, and the rows written are the ones GsbCutoffService would have written:
 *
 *  • below the personal-BV minimum  → `below_600bv`, all-zero row, no
 *    carry-forward row touched (the engine returns before reading CF);
 *  • eligible but with no group BV today, no carry-forward and no result row
 *    for the date → `no_match` with zeros and power_side_after = 'L' (the
 *    engine's tie-break on 0 vs 0), plus the zero carry-forward row its
 *    firstOrCreate + update would have left behind.
 *
 * Anything else — a frozen distributor, an existing row for the date, any BV,
 * any carry-forward, or a plan whose smallest slab threshold is 0 — is handed
 * back to the normal compute/settle path untouched.
 */
final class GsbIdleCutoffBatch
{
    /** Rows per INSERT — large enough to matter, small enough for max_allowed_packet. */
    private const CHUNK = 500;

    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly BvLedgerService $bvLedger,
    ) {}

    /**
     * Split the day's distributors into those the engine must actually run for
     * and those whose rows can be written in bulk.
     *
     * @param  Collection<int, Distributor>  $distributors  as loaded by the command (id, gsb_frozen_at)
     * @return array{engine: Collection<int, Distributor>, below_min: list<int>, idle: array<int, string|null>}
     *                                                                                                          idle maps distributor id => the power side stored
     *                                                                                                          on their all-zero carry-forward row, which the
     *                                                                                                          engine records as power_side_before
     */
    public function partition(Collection $distributors, Carbon $date): array
    {
        $minSlabMatchedPaise = $this->plan->gsbMinSlabMatchedBvPaise();

        // A zero-BV distributor can only be inert if every payable slab needs
        // more than zero matched BV. If the plan is configured otherwise, take
        // no shortcut at all.
        if ($minSlabMatchedPaise <= 0) {
            return ['engine' => $distributors, 'below_min' => [], 'idle' => []];
        }

        $ids = $distributors->map(fn (Distributor $d): int => (int) $d->id)->all();
        $minBvPaise = $this->plan->gsbMinBvPaise();
        $dateStr = $date->toDateString();

        $alreadyHasRow = DB::table('gsb_cutoff_results')
            ->whereIn('distributor_id', $ids)
            ->whereDate('cutoff_date', $dateStr)
            ->pluck('distributor_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        $hasGroupBv = DB::table('group_bv_daily')
            ->whereIn('distributor_id', $ids)
            ->whereDate('date', $dateStr)
            ->where(function ($q): void {
                $q->where('left_bv_paise', '!=', 0)->orWhere('right_bv_paise', '!=', 0);
            })
            ->pluck('distributor_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        // A carry-forward row carrying nothing is not state. After one idle day
        // every eligible distributor HAS such a row — settle()'s no-match branch
        // creates it — so treating its mere existence as state would confine this
        // shortcut to the first day of a replay. What matters is whether it
        // carries BV; its recorded power side is read rather than ignored,
        // because the next row's power_side_before is exactly that value.
        $carryforward = DB::table('gsb_carryforward')
            ->whereIn('distributor_id', $ids)
            ->get(['distributor_id', 'power_side', 'power_side_bv_paise', 'slab1_weaker_bv_paise']);

        $zeroCarryforwardSide = [];
        $hasCarryforward = [];

        foreach ($carryforward as $row) {
            $id = (int) $row->distributor_id;

            if ((int) $row->power_side_bv_paise !== 0 || (int) $row->slab1_weaker_bv_paise !== 0) {
                $hasCarryforward[$id] = true;

                continue;
            }

            $zeroCarryforwardSide[$id] = $row->power_side;
        }

        $belowMin = [];
        $idle = [];
        $engine = [];

        foreach ($distributors as $distributor) {
            $id = (int) $distributor->id;

            if ($alreadyHasRow->has($id)) {
                $engine[] = $distributor;

                continue;
            }

            if ($this->bvLedger->totalPersonalBvPaise($id) < $minBvPaise) {
                $belowMin[] = $id;

                continue;
            }

            // Eligible: inert only with no BV today, no carry-forward and no
            // operator freeze (a freeze changes nothing on this path, but a
            // frozen distributor is never worth a shortcut).
            if ($distributor->gsb_frozen_at === null
                && ! $hasGroupBv->has($id)
                && ! isset($hasCarryforward[$id])) {
                $idle[$id] = $zeroCarryforwardSide[$id] ?? null;

                continue;
            }

            $engine[] = $distributor;
        }

        return [
            'engine' => new Collection($engine),
            'below_min' => $belowMin,
            'idle' => $idle,
        ];
    }

    /**
     * Write the batched rows. Returns the number of cut-off rows inserted.
     *
     * @param  list<int>  $belowMinIds
     * @param  array<int, string|null>  $idle  distributor id => stored power side
     */
    public function write(array $belowMinIds, array $idle, Carbon $date): int
    {
        $idleIds = array_keys($idle);

        $now = Carbon::now();
        // Serialised exactly as Eloquent's `date` cast writes it, so a bulk row
        // and an engine-settled row are byte-identical on every driver (MySQL
        // truncates to the DATE column; SQLite stores the string verbatim).
        $dateStr = $date->copy()->startOfDay()->format('Y-m-d H:i:s');
        $written = 0;

        foreach (array_chunk($belowMinIds, self::CHUNK) as $chunk) {
            DB::table('gsb_cutoff_results')->insert(array_map(
                fn (int $id): array => $this->row($id, $dateStr, GsbCutoffResult::STATUS_BELOW_600BV, null, $now),
                $chunk,
            ));
            $written += count($chunk);
        }

        foreach (array_chunk($idleIds, self::CHUNK) as $chunk) {
            DB::table('gsb_cutoff_results')->insert(array_map(
                // power_side_after 'L' mirrors the engine's tie-break when both
                // sides are 0; power_side_before is whatever the carry-forward
                // row already recorded — what computeForDistributor() reads.
                fn (int $id): array => $this->row(
                    $id,
                    $dateStr,
                    GsbCutoffResult::STATUS_NO_MATCH,
                    'L',
                    $now,
                    $idle[$id] ?? null,
                ),
                $chunk,
            ));
            $written += count($chunk);
        }

        // settle()'s no-match branch leaves behind a zero carry-forward row whose
        // power_side is the day's stronger side — 'L' on a 0-vs-0 tie. Create it
        // for anyone without one, then set the side on the rest: together that is
        // what the engine's firstOrCreate + update leaves behind.
        foreach (array_chunk($idleIds, self::CHUNK) as $chunk) {
            DB::table('gsb_carryforward')->insertOrIgnore(array_map(
                fn (int $id): array => [
                    'distributor_id' => $id,
                    'power_side_bv_paise' => 0,
                    'power_side' => 'L',
                    'slab1_weaker_bv_paise' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $chunk,
            ));

            DB::table('gsb_carryforward')
                ->whereIn('distributor_id', $chunk)
                ->update(['power_side' => 'L', 'updated_at' => $now]);
        }

        return $written;
    }

    /**
     * One all-zero result row, field for field as saveResult() would write it.
     *
     * @return array<string, mixed>
     */
    private function row(
        int $distributorId,
        string $dateStr,
        string $status,
        ?string $powerSideAfter,
        Carbon $now,
        ?string $powerSideBefore = null,
    ): array {
        return [
            'distributor_id' => $distributorId,
            'cutoff_date' => $dateStr,
            'left_bv_paise' => 0,
            'right_bv_paise' => 0,
            'weaker_bv_paise' => 0,
            'slab' => null,
            'score' => null,
            'score_value_paise' => null,
            'gross_gsb_paise' => 0,
            'admin_charge_paise' => 0,
            'tds_paise' => 0,
            'net_gsb_paise' => 0,
            'power_cf_before_paise' => 0,
            'power_side_before' => $powerSideBefore,
            'power_cf_after_paise' => 0,
            'power_side_after' => $powerSideAfter,
            'slab1_weaker_cf_before_paise' => 0,
            'slab1_weaker_cf_after_paise' => 0,
            'status' => $status,
            'failure_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
