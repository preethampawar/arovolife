<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\DTOs\GenosLedgerDay;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Transaction-style Genos BV ledger for one distributor: every per-order
 * credit into their left/right group BV accumulator, day by day, closed by
 * that day's cut-off settlement (the debit side).
 *
 * Credits are DERIVED — not double-written. bv_propagation_log holds one row
 * per propagated paid order (buyer + BV); joining it through the closure table
 * against this distributor's direct children reproduces exactly the side
 * attribution GroupBvAccumulatorService used when it accumulated the BV.
 * The settlement figures come verbatim from gsb_cutoff_results — never
 * recomputed here.
 */
final class GenosBvLedgerService
{
    /**
     * Paginate the ledger by day (newest first).
     *
     * @return LengthAwarePaginator<int, GenosLedgerDay>
     */
    public function paginateDays(
        int $distributorId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $perPage = 10,
    ): LengthAwarePaginator {
        $creditDates = $this->creditsBase($distributorId)
            ->when($from, fn ($b) => $b->where('bpl.date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('bpl.date', '<=', $to->toDateString()))
            ->select('bpl.date');

        $cutoffDates = DB::table('gsb_cutoff_results')
            ->where('distributor_id', $distributorId)
            ->when($from, fn ($b) => $b->where('cutoff_date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('cutoff_date', '<=', $to->toDateString()))
            ->select('cutoff_date as date');

        // UNION (not UNION ALL) dedupes dates appearing in both sources.
        $days = DB::query()
            ->fromSub($creditDates->union($cutoffDates), 'ledger_dates')
            ->orderByDesc('date')
            ->paginate($perPage);

        $dates = collect($days->items())->pluck('date')->map(
            fn ($d) => Carbon::parse((string) $d)->toDateString(),
        );

        $credits = $this->creditsBase($distributorId)
            ->join('distributors as buyer', 'buyer.id', '=', 'bpl.distributor_id')
            ->leftJoin('users as buyer_user', 'buyer_user.id', '=', 'buyer.user_id')
            ->whereIn('bpl.date', $dates->all())
            ->select(
                'bpl.date',
                'bpl.order_id',
                'bpl.bv_paise',
                'bpl.created_at',
                'dc.placement_side as side',
                'buyer.adn as buyer_adn',
                'buyer_user.full_name as buyer_name',
            )
            ->orderByDesc('bpl.created_at')
            ->orderByDesc('bpl.order_id')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse((string) $row->date)->toDateString());

        $cutoffs = GsbCutoffResult::where('distributor_id', $distributorId)
            ->whereIn('cutoff_date', $dates->all())
            ->get()
            ->keyBy(fn (GsbCutoffResult $r) => $r->cutoff_date->toDateString());

        $dayGroups = $dates->map(fn (string $date) => new GenosLedgerDay(
            date: $date,
            credits: $credits->get($date)?->values()->all() ?? [],
            cutoff: $cutoffs->get($date),
        ))->values();

        // Rebuilt (rather than $days->through()) so the paginator carries the
        // day-group value type instead of the raw date rows.
        return new LengthAwarePaginator(
            $dayGroups,
            $days->total(),
            $days->perPage(),
            $days->currentPage(),
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /**
     * Base join reproducing GroupBvAccumulatorService's side attribution:
     * a propagated order credits this distributor when its buyer sits in the
     * subtree of one of the distributor's direct placement children; that
     * child's placement_side is the side credited.
     */
    private function creditsBase(int $distributorId): Builder
    {
        return DB::table('bv_propagation_log as bpl')
            ->join('genealogy_closure as gc', 'gc.descendant_id', '=', 'bpl.distributor_id')
            ->join('distributors as dc', function ($join) use ($distributorId) {
                $join->on('dc.id', '=', 'gc.ancestor_id')
                    ->where('dc.placement_parent_id', '=', $distributorId);
            })
            ->whereIn('dc.placement_side', ['L', 'R']);
    }
}
