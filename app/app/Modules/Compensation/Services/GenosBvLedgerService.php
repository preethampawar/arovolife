<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\DTOs\GenosLedgerDay;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Transaction-style Genos BV ledger for one distributor: every per-order
 * credit into their left/right group BV accumulator, any cancelled-order
 * reversals, day by day, closed by that day's cut-off settlement.
 *
 * Credits come from group_bv_credits — the per-ancestor snapshot written by
 * GroupBvAccumulatorService in the same transaction as the accumulation, so
 * the ledger shows exactly what was credited (after any reversal-debt
 * consumption) even if the tree changes later. Reversals come from
 * group_bv_reversals. The settlement figures come verbatim from
 * gsb_cutoff_results — never recomputed here.
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
        $creditDates = DB::table('group_bv_credits')
            ->where('ancestor_id', $distributorId)
            ->when($from, fn ($b) => $b->where('date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('date', '<=', $to->toDateString()))
            ->select('date');

        $reversalDates = DB::table('group_bv_reversals')
            ->where('ancestor_id', $distributorId)
            ->when($from, fn ($b) => $b->where('date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('date', '<=', $to->toDateString()))
            ->select('date');

        $cutoffDates = DB::table('gsb_cutoff_results')
            ->where('distributor_id', $distributorId)
            ->when($from, fn ($b) => $b->where('cutoff_date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('cutoff_date', '<=', $to->toDateString()))
            ->select('cutoff_date as date');

        // UNION (not UNION ALL) dedupes dates appearing in more than one source.
        $days = DB::query()
            ->fromSub($creditDates->union($reversalDates)->union($cutoffDates), 'ledger_dates')
            ->orderByDesc('date')
            ->paginate($perPage);

        $dates = collect($days->items())->pluck('date')->map(
            fn ($d) => Carbon::parse((string) $d)->toDateString(),
        );

        $credits = DB::table('group_bv_credits as gbc')
            ->join('bv_propagation_log as bpl', 'bpl.order_id', '=', 'gbc.order_id')
            ->join('distributors as buyer', 'buyer.id', '=', 'bpl.distributor_id')
            ->leftJoin('users as buyer_user', 'buyer_user.id', '=', 'buyer.user_id')
            ->where('gbc.ancestor_id', $distributorId)
            ->whereIn('gbc.date', $dates->all())
            ->select(
                'gbc.date',
                'gbc.order_id',
                'gbc.bv_paise',
                'gbc.debt_consumed_paise',
                'gbc.created_at',
                'gbc.side',
                'buyer.adn as buyer_adn',
                'buyer_user.full_name as buyer_name',
            )
            ->orderByDesc('gbc.created_at')
            ->orderByDesc('gbc.order_id')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse((string) $row->date)->toDateString());

        $reversals = DB::table('group_bv_reversals as gbr')
            ->join('bv_propagation_log as bpl', 'bpl.order_id', '=', 'gbr.order_id')
            ->join('distributors as buyer', 'buyer.id', '=', 'bpl.distributor_id')
            ->leftJoin('users as buyer_user', 'buyer_user.id', '=', 'buyer.user_id')
            ->where('gbr.ancestor_id', $distributorId)
            ->whereIn('gbr.date', $dates->all())
            ->select(
                'gbr.date',
                'gbr.order_id',
                'gbr.bv_paise',
                'gbr.absorbed_paise',
                'gbr.debt_paise',
                'gbr.created_at',
                'gbr.side',
                'buyer.adn as buyer_adn',
                'buyer_user.full_name as buyer_name',
            )
            ->orderByDesc('gbr.created_at')
            ->orderByDesc('gbr.order_id')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse((string) $row->date)->toDateString());

        $cutoffs = GsbCutoffResult::where('distributor_id', $distributorId)
            ->whereIn('cutoff_date', $dates->all())
            ->get()
            ->keyBy(fn (GsbCutoffResult $r) => $r->cutoff_date->toDateString());

        $dayGroups = $dates->map(fn (string $date) => new GenosLedgerDay(
            date: $date,
            credits: $credits->get($date)?->values()->all() ?? [],
            reversals: $reversals->get($date)?->values()->all() ?? [],
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
     * Outstanding cancelled-order reversal debt per side (paise) — BV that
     * will be deducted from this distributor's upcoming group BV on that
     * side before anything new is credited.
     *
     * @return array{L: int, R: int}
     */
    public function openDebts(int $distributorId): array
    {
        $rows = DB::table('group_bv_debts')
            ->where('distributor_id', $distributorId)
            ->where('bv_paise', '>', 0)
            ->pluck('bv_paise', 'side');

        return [
            'L' => (int) ($rows['L'] ?? 0),
            'R' => (int) ($rows['R'] ?? 0),
        ];
    }
}
