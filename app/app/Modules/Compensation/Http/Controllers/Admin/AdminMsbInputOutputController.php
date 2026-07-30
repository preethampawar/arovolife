<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Compensation\Models\MsbDailyPool;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MSB "Input & Output Per Day" calculation report (KP 2026-07-30).
 *
 * One block per cut-off day showing the pool arithmetic behind every credit:
 * the day's total received BV, the 3% MSB pool, each earning sponsor with the
 * points they accrued, the one point value the day resolved to, and their
 * income — footed by the day's total points and total income, which tally to
 * the pool minus the flooring remainder.
 *
 * Driven FROM msb_daily_pools (left-joining the credits) so a day whose pool
 * went unspent still appears rather than silently vanishing.
 *
 * Day/week numbers deliberately share the GSB report's anchor — the first
 * pooled day of either engine — so "Day 1" means the same calendar day on both
 * reports instead of drifting apart when the pools start on different dates.
 */
final class AdminMsbInputOutputController extends Controller
{
    private const DAYS_PER_PAGE = 10;

    public function index(Request $request): View
    {
        [$day, $week, $from, $to] = $this->filters($request);

        $anchor = $this->anchorDate();

        $pools = $this->poolQuery($anchor, $day, $week, $from, $to)
            ->paginate(self::DAYS_PER_PAGE)
            ->withQueryString();

        $dates = array_values(array_map(
            fn (MsbDailyPool $p) => $p->cutoff_date->toDateString(),
            $pools->items(),
        ));

        return view('admin.compensation.msb-input-output.index', [
            'pools' => $pools,
            'earners' => $this->earners($dates),
            'anchor' => $anchor,
            'day' => $day,
            'week' => $week,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);
    }

    public function export(Request $request): Response
    {
        [$day, $week, $from, $to] = $this->filters($request);

        $anchor = $this->anchorDate();
        $pools = $this->poolQuery($anchor, $day, $week, $from, $to)->get();
        $earners = $this->earners(array_values(
            $pools->map(fn (MsbDailyPool $p) => $p->cutoff_date->toDateString())->all(),
        ));

        $csv = "Day,Week,Date,Day Total Received BV,MSB Pool (Rs),Sponsor ADN,Sponsor Name,MSB Points,Point Value (Rs),Income (Rs)\n";

        foreach ($pools as $pool) {
            $dateStr = $pool->cutoff_date->toDateString();
            $dayNo = $this->dayNumber($anchor, $pool->cutoff_date);
            $weekNo = $dayNo === null ? null : intdiv($dayNo - 1, 7) + 1;
            $totalPoints = 0;
            $totalIncome = 0;

            foreach ($earners[$dateStr] ?? [] as $row) {
                $totalPoints += (int) $row->msb_points;
                $totalIncome += (int) $row->income_paise;

                $csv .= implode(',', [
                    $dayNo ?? '',
                    $weekNo ?? '',
                    $dateStr,
                    number_format($pool->company_bv_paise / 100, 2, '.', ''),
                    number_format($pool->pool_paise / 100, 2, '.', ''),
                    $this->csvStr((string) ($row->adn ?? '')),
                    $this->csvStr((string) ($row->full_name ?? '')),
                    (int) $row->msb_points,
                    number_format(((int) $row->point_value_paise) / 100, 2, '.', ''),
                    number_format($row->income_paise / 100, 2, '.', ''),
                ])."\n";
            }

            $csv .= implode(',', [
                $dayNo ?? '',
                $weekNo ?? '',
                $dateStr,
                number_format($pool->company_bv_paise / 100, 2, '.', ''),
                number_format($pool->pool_paise / 100, 2, '.', ''),
                '',
                $this->csvStr('DAY TOTAL'),
                $totalPoints,
                number_format($pool->point_value_paise / 100, 2, '.', ''),
                number_format($totalIncome / 100, 2, '.', ''),
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="msb-input-output-'.now()->toDateString().'.csv"',
        ]);
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?Carbon, 3: ?Carbon}
     */
    private function filters(Request $request): array
    {
        $request->validate([
            'day' => ['nullable', 'integer', 'min:1'],
            'week' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [
            $request->query('day') !== null && $request->query('day') !== '' ? (int) $request->query('day') : null,
            $request->query('week') !== null && $request->query('week') !== '' ? (int) $request->query('week') : null,
            $request->query('from') ? Carbon::parse((string) $request->query('from')) : null,
            $request->query('to') ? Carbon::parse((string) $request->query('to')) : null,
        ];
    }

    /**
     * Day 1 of the report's numbering: the earliest pooled day of EITHER
     * engine, so this report and the GSB one always agree on what "Day 3" is.
     */
    private function anchorDate(): ?Carbon
    {
        $candidates = array_filter([
            GsbDailyPool::min('cutoff_date'),
            MsbDailyPool::min('cutoff_date'),
        ]);

        if ($candidates === []) {
            return null;
        }

        return Carbon::parse((string) min($candidates))->startOfDay();
    }

    public function dayNumber(?Carbon $anchor, Carbon $date): ?int
    {
        if ($anchor === null) {
            return null;
        }

        return (int) $anchor->diffInDays($date->copy()->startOfDay()) + 1;
    }

    /** @return Builder<MsbDailyPool> */
    private function poolQuery(?Carbon $anchor, ?int $day, ?int $week, ?Carbon $from, ?Carbon $to): Builder
    {
        return MsbDailyPool::query()
            ->when($day !== null && $anchor !== null, fn ($q) => $q->whereDate(
                'cutoff_date', $anchor->copy()->addDays($day - 1)->toDateString(),
            ))
            ->when($week !== null && $anchor !== null, fn ($q) => $q
                ->whereDate('cutoff_date', '>=', $anchor->copy()->addDays(($week - 1) * 7)->toDateString())
                ->whereDate('cutoff_date', '<=', $anchor->copy()->addDays(($week - 1) * 7 + 6)->toDateString()))
            ->when($from, fn ($q) => $q->whereDate('cutoff_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('cutoff_date', '<=', $to->toDateString()))
            ->orderByDesc('cutoff_date');
    }

    /**
     * Per-day, per-sponsor MSB credits. One sponsor can be credited by several
     * sponsees on the same day, so the points and income are summed per sponsor
     * — KP's sheet lists one line per earning distributor.
     *
     * @param  list<string>  $dates
     * @return array<string, list<\stdClass>> date → rows {sponsor_id, adn, full_name, msb_points, point_value_paise, income_paise}
     */
    private function earners(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        return DB::table('mentorship_bonus_results as mbr')
            ->join('distributors as d', 'd.id', '=', 'mbr.sponsor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->where('mbr.status', 'credited')
            ->whereIn(DB::raw('DATE(mbr.cutoff_date)'), $dates)
            ->groupBy('mbr.cutoff_date', 'mbr.sponsor_id', 'd.adn', 'u.full_name')
            ->select('mbr.cutoff_date', 'mbr.sponsor_id', 'd.adn', 'u.full_name')
            ->selectRaw('COALESCE(SUM(mbr.msb_points), 0) as msb_points')
            ->selectRaw('MAX(mbr.msb_point_value_paise) as point_value_paise')
            ->selectRaw('COALESCE(SUM(mbr.mb_gross_paise), 0) as income_paise')
            ->orderByDesc('msb_points')
            ->get()
            ->groupBy(fn (\stdClass $row) => Carbon::parse($row->cutoff_date)->toDateString())
            ->map(fn ($rows) => array_values($rows->all()))
            ->all();
    }

    private function csvStr(string $value): string
    {
        $value = str_replace('"', '""', $value);
        if (preg_match('/^[=+\-@]/', $value)) {
            $value = "\t".$value;
        }

        return '"'.$value.'"';
    }
}
