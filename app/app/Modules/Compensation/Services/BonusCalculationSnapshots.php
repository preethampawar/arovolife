<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\FortuneMonthlyPool;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Compensation\Models\MsbDailyPool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The frozen economics behind each bonus calculation report, keyed by the
 * period (cut-off date or month start, Y-m-d) so the report can show the
 * client-requested header + "formula, then the formula with this period's
 * values" block above the rows.
 *
 * GSB / MSB / GBB / Fortune read their pool rows verbatim — nothing is
 * recomputed. ADC and Lifetime Awards have no pool row, so their blocks
 * aggregate the period's result rows and pair them with the CURRENT plan
 * settings (rate / cap / award budget), which the views label as such.
 *
 * Rank Bonus reads its frozen Rank-1 points model off rank_bonus_results the
 * same way. This class is the ONE place a report gets its header figures
 * from — views never recompute a frozen number, they only display it.
 */
final class BonusCalculationSnapshots
{
    /** Daily reports cap the header blocks so a 50-row page cannot render 50 of them. */
    public const int MAX_DAILY_BLOCKS = 5;

    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly AreteDevelopmentCenterBonusService $adc,
    ) {}

    /**
     * The periods whose blocks a monthly report shows: the filtered month,
     * else every month among the rows on the current page, newest first.
     *
     * @param  array<int, object>  $items  the page's rows
     * @return array<int, string> Y-m-d month starts
     */
    public function monthsOnPage(array $items, string $column, ?string $filteredMonth): array
    {
        if ($filteredMonth !== null) {
            return [$filteredMonth.'-01'];
        }

        return collect($items)
            ->map(fn (object $r): string => Carbon::parse((string) $r->{$column})->toDateString())
            ->unique()->sortDesc()->values()->all();
    }

    /**
     * The distinct cut-off dates among a daily report's page rows, newest
     * first — the caller takes the first MAX_DAILY_BLOCKS.
     *
     * @param  array<int, object>  $items  the page's rows
     * @return Collection<int, string> Y-m-d
     */
    public function datesOnPage(array $items, string $column): Collection
    {
        return collect($items)
            ->map(fn (object $r): string => Carbon::parse((string) $r->{$column})->toDateString())
            ->unique()->sortDesc()->values();
    }

    /**
     * @param  array<int, string>  $dates  Y-m-d
     * @return Collection<string, GsbDailyPool>
     */
    public function gsbDays(array $dates): Collection
    {
        if ($dates === []) {
            return collect();
        }

        // Range + key filter rather than whereIn: the column is a DATE on
        // MySQL but a datetime string on SQLite, and a range matches both.
        return GsbDailyPool::query()
            ->whereBetween('cutoff_date', [min($dates), max($dates).' 23:59:59'])
            ->orderByDesc('cutoff_date')
            ->get()
            ->toBase() // Eloquent's only() filters by primary key, not by our date keys
            ->keyBy(fn (GsbDailyPool $p): string => $p->cutoff_date->toDateString())
            ->only($dates);
    }

    /**
     * @param  array<int, string>  $dates  Y-m-d
     * @return Collection<string, MsbDailyPool>
     */
    public function msbDays(array $dates): Collection
    {
        if ($dates === []) {
            return collect();
        }

        // Range + key filter rather than whereIn: the column is a DATE on
        // MySQL but a datetime string on SQLite, and a range matches both.
        return MsbDailyPool::query()
            ->whereBetween('cutoff_date', [min($dates), max($dates).' 23:59:59'])
            ->orderByDesc('cutoff_date')
            ->get()
            ->toBase() // Eloquent's only() filters by primary key, not by our date keys
            ->keyBy(fn (MsbDailyPool $p): string => $p->cutoff_date->toDateString())
            ->only($dates);
    }

    /**
     * @param  array<int, string>  $monthStarts  Y-m-d
     * @return Collection<string, GbbMonthlyPool>
     */
    public function gbbMonths(array $monthStarts): Collection
    {
        if ($monthStarts === []) {
            return collect();
        }

        return GbbMonthlyPool::query()
            ->whereIn('month_start', $monthStarts)
            ->orderByDesc('month_start')
            ->get()
            ->keyBy(fn (GbbMonthlyPool $p): string => Carbon::parse((string) $p->month_start)->toDateString());
    }

    /**
     * Pool rows with their frozen per-level cascade rows eager-loaded.
     *
     * @param  array<int, string>  $monthStarts  Y-m-d
     * @return Collection<string, FortuneMonthlyPool>
     */
    public function fortuneMonths(array $monthStarts): Collection
    {
        if ($monthStarts === []) {
            return collect();
        }

        return FortuneMonthlyPool::query()
            ->with(['levels' => fn ($q) => $q->orderBy('matrix_level')])
            ->whereIn('month_start', $monthStarts)
            ->orderByDesc('month_start')
            ->get()
            ->keyBy(fn (FortuneMonthlyPool $p): string => Carbon::parse((string) $p->month_start)->toDateString());
    }

    /**
     * Per-month ADC aggregate: how many centres were paid, their combined net
     * member BV, what the flat rate would have paid before caps and what was
     * actually paid after each centre's cap. Rate and cap are the CURRENT plan
     * settings; the per-row gross is what the engine froze.
     *
     * @param  array<int, string>  $monthStarts  Y-m-d
     * @return array<string, array{
     *     centers: int, member_bv_paise: int, uncapped_paise: int, gross_paise: int,
     *     capped_centers: int, rate_bp: int, cap_paise: int, computed_at: ?Carbon
     * }>
     */
    public function adcMonths(array $monthStarts): array
    {
        if ($monthStarts === []) {
            return [];
        }

        $rateBp = $this->plan->adcRateBp();
        $capPaise = $this->plan->adcCapPaise();

        $rows = DB::table('adc_bonus_results as abr')
            ->leftJoin('arete_centers as ac', 'ac.id', '=', 'abr.center_id')
            ->whereIn('abr.month_start', $monthStarts)
            ->select('abr.month_start', 'abr.total_attributed_bv_paise', 'abr.gross_paise', 'abr.created_at', 'ac.monthly_cap_override_paise')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = Carbon::parse((string) $row->month_start)->toDateString();
            $bv = (int) $row->total_attributed_bv_paise;
            $gross = (int) $row->gross_paise;
            // The engine's own arithmetic, so "before caps" can never drift
            // from what the run would pay.
            $economics = $this->adc->grossFor(
                $bv,
                $row->monthly_cap_override_paise !== null ? (int) $row->monthly_cap_override_paise : null,
            );
            $createdAt = $row->created_at !== null ? Carbon::parse((string) $row->created_at) : null;

            $agg = $out[$key] ?? [
                'centers' => 0, 'member_bv_paise' => 0, 'uncapped_paise' => 0, 'gross_paise' => 0,
                'capped_centers' => 0, 'rate_bp' => $rateBp, 'cap_paise' => $capPaise, 'computed_at' => null,
            ];
            $agg['centers']++;
            $agg['member_bv_paise'] += $bv;
            $agg['uncapped_paise'] += $economics['flat_paise'];
            $agg['gross_paise'] += $gross;
            if ($gross < $economics['flat_paise']) {
                $agg['capped_centers']++;
            }
            if ($createdAt !== null && ($agg['computed_at'] === null || $createdAt->greaterThan($agg['computed_at']))) {
                $agg['computed_at'] = $createdAt;
            }
            $out[$key] = $agg;
        }

        krsort($out);

        return $out;
    }

    /**
     * Per-month Lifetime Awards aggregate, one line per rank: milestones
     * triggered, delivered, cash gross / TDS / net, next to the rank's CURRENT
     * award budget from the rank ladder.
     *
     * @param  array<int, string>  $monthStarts  Y-m-d
     * @return array<string, array{
     *     milestones: int, delivered: int, computed_at: ?Carbon,
     *     ranks: list<array{rank: int, name: string, milestones: int, delivered: int, cash: int, budget_paise: int, gross_paise: int, tds_paise: int, net_paise: int}>
     * }>
     */
    public function awRwMonths(array $monthStarts): array
    {
        if ($monthStarts === []) {
            return [];
        }

        $rows = DB::table('lifetime_award_milestones')
            ->whereIn('triggered_month', $monthStarts)
            ->selectRaw('triggered_month, rank_number')
            ->selectRaw('COUNT(*) as milestones')
            ->selectRaw("SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN disbursement_type = 'cash' THEN 1 ELSE 0 END) as cash")
            ->selectRaw('COALESCE(SUM(gross_paise), 0) as gross_paise')
            ->selectRaw('COALESCE(SUM(tds_paise), 0) as tds_paise')
            ->selectRaw('COALESCE(SUM(net_paise), 0) as net_paise')
            ->selectRaw('MAX(created_at) as computed_at')
            ->groupBy('triggered_month', 'rank_number')
            ->orderBy('rank_number')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = Carbon::parse((string) $row->triggered_month)->toDateString();
            $rank = (int) $row->rank_number;
            $computedAt = $row->computed_at !== null ? Carbon::parse((string) $row->computed_at) : null;

            $agg = $out[$key] ?? ['milestones' => 0, 'delivered' => 0, 'computed_at' => null, 'ranks' => []];
            $agg['milestones'] += (int) $row->milestones;
            $agg['delivered'] += (int) $row->delivered;
            if ($computedAt !== null && ($agg['computed_at'] === null || $computedAt->greaterThan($agg['computed_at']))) {
                $agg['computed_at'] = $computedAt;
            }
            $agg['ranks'][] = [
                'rank' => $rank,
                'name' => $this->plan->rankName($rank),
                'milestones' => (int) $row->milestones,
                'delivered' => (int) $row->delivered,
                'cash' => (int) $row->cash,
                'budget_paise' => $this->plan->lifetimeAwardBudgetPaise($rank),
                'gross_paise' => (int) $row->gross_paise,
                'tds_paise' => (int) $row->tds_paise,
                'net_paise' => (int) $row->net_paise,
            ];
            $out[$key] = $agg;
        }

        krsort($out);

        return $out;
    }

    /**
     * MAX() is safe because every Rank-1 row of the month carries the same
     * snapshot; qualifier_count is the engine's own payable count (held rows
     * and AO-GO grantee rows already excluded). Null when the month has no
     * Rank-1 rows.
     *
     * @return ?array{
     *     turnover_paise: int, envelope_bp: int, pool_pct: float, pool_paise: int,
     *     qualifiers: int, rap_points: ?int, aogo_points: int, total_points: ?int,
     *     point_value_paise: ?int, computed_at: ?Carbon
     * }
     */
    public function rankBonusMonth(Carbon $month): ?array
    {
        $agg = DB::table('rank_bonus_results')
            ->where('month_start', $month->toDateString())
            ->where('rank_number', 1)
            ->selectRaw('MAX(company_turnover_paise) as turnover_paise')
            ->selectRaw('MAX(pool_paise) as pool_paise')
            ->selectRaw('MAX(qualifier_count) as qualifier_count')
            ->selectRaw('MAX(rap_points) as rap_points')
            ->selectRaw('MAX(total_points) as total_points')
            ->selectRaw('MAX(point_value_paise) as point_value_paise')
            ->selectRaw('MAX(created_at) as computed_at')
            ->selectRaw('COUNT(*) as row_count')
            ->first();

        if ($agg === null || (int) $agg->row_count === 0) {
            return null;
        }

        $aogoPoints = (int) RankAogoGrant::query()
            ->where('month_start', $month->toDateString())
            ->where('status', '!=', RankAogoGrant::STATUS_VOIDED)
            ->sum('points');

        return [
            'turnover_paise' => (int) $agg->turnover_paise,
            'envelope_bp' => $this->plan->rankEnvelopeBp(),
            'pool_pct' => $this->plan->rankPoolPct(1),
            'pool_paise' => (int) $agg->pool_paise,
            'qualifiers' => (int) $agg->qualifier_count,
            'rap_points' => $agg->rap_points !== null ? (int) $agg->rap_points : null,
            'aogo_points' => $aogoPoints,
            'total_points' => $agg->total_points !== null ? (int) $agg->total_points : null,
            'point_value_paise' => $agg->point_value_paise !== null ? (int) $agg->point_value_paise : null,
            'computed_at' => $agg->computed_at !== null ? Carbon::parse($agg->computed_at) : null,
        ];
    }
}
