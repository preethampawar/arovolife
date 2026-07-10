<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\PersonalBvTitleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AdminMsbCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
    ) {}

    public function index(Request $request): View
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'in:credited,failed'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $from = $request->query('from') ? Carbon::parse((string) $request->query('from')) : null;
        $to = $request->query('to') ? Carbon::parse((string) $request->query('to')) : null;
        $status = $request->query('status');

        $rows = $this->queryRows($q, $from, $to, $status);

        $sponsorIds = collect($rows->items())->pluck('sponsor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($sponsorIds);

        return view('admin.compensation.msb-calculation.index', [
            'rows' => $rows,
            'q' => $q ?: null,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'status' => $status,
            'titleService' => $this->titleService,
            'personalBvMap' => $personalBvMap,
        ]);
    }

    public function export(Request $request): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'in:credited,failed'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $from = $request->query('from') ? Carbon::parse((string) $request->query('from')) : null;
        $to = $request->query('to') ? Carbon::parse((string) $request->query('to')) : null;
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $from, $to, $status)->get();

        $sponsorIds = $rows->pluck('sponsor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($sponsorIds);

        $csv = "SNo,Sponsor ADN,Sponsor Name,Title,Date,Sponsee ADN,Sponsee Name,Sponsee GSB (Rs),Rate %,Gross MSB (Rs),TDS (Rs),Net MSB (Rs),Status\n";

        foreach ($rows as $i => $row) {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->sponsor_id] ?? 0)->title ?? '';
            $csv .= implode(',', [
                $i + 1,
                $this->csvStr($row->sponsor_adn),
                $this->csvStr($row->sponsor_name ?? ''),
                $this->csvStr($title),
                Carbon::parse($row->cutoff_date)->toDateString(),
                $this->csvStr($row->sponsee_adn),
                $this->csvStr($row->sponsee_name ?? ''),
                number_format($row->sponsee_gsb_paise / 100, 2, '.', ''),
                $row->mb_rate_pct.'%',
                number_format($row->mb_gross_paise / 100, 2, '.', ''),
                number_format($row->mb_tds_paise / 100, 2, '.', ''),
                number_format($row->mb_paise / 100, 2, '.', ''),
                $this->csvStr($row->status),
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="msb-calculation-'.now()->toDateString().'.csv"',
        ]);
    }

    private function queryRows(string $q, ?Carbon $from, ?Carbon $to, ?string $status): LengthAwarePaginator
    {
        return $this->buildQuery($q, $from, $to, $status)
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    private function buildQuery(string $q, ?Carbon $from, ?Carbon $to, ?string $status): Builder
    {
        return DB::table('mentorship_bonus_results as mbr')
            ->join('distributors as sponsor', 'sponsor.id', '=', 'mbr.sponsor_id')
            ->leftJoin('users as sponsor_user', 'sponsor_user.id', '=', 'sponsor.user_id')
            ->join('distributors as sponsee', 'sponsee.id', '=', 'mbr.sponsee_id')
            ->leftJoin('users as sponsee_user', 'sponsee_user.id', '=', 'sponsee.user_id')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('sponsor.adn', 'like', "%{$q}%")
                ->orWhere('sponsor_user.full_name', 'like', "%{$q}%")
            ))
            ->when($from, fn ($b) => $b->where('mbr.cutoff_date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('mbr.cutoff_date', '<=', $to->toDateString()))
            ->when($status, fn ($b) => $b->where('mbr.status', $status))
            ->select(
                'mbr.id',
                'mbr.sponsor_id',
                'mbr.sponsee_id',
                'mbr.cutoff_date',
                'mbr.sponsee_gsb_paise',
                'mbr.mb_rate_pct',
                'mbr.mb_gross_paise',
                'mbr.mb_tds_paise',
                'mbr.mb_paise',
                'mbr.status',
                'sponsor.adn as sponsor_adn',
                'sponsor_user.full_name as sponsor_name',
                'sponsee.adn as sponsee_adn',
                'sponsee_user.full_name as sponsee_name',
            )
            ->orderByDesc('mbr.cutoff_date')
            ->orderByDesc('mbr.id');
    }

    private function csvStr(string $value): string
    {
        $value = str_replace('"', '""', $value);
        if (preg_match('/^[=+\-@]/', $value)) {
            $value = "\t".$value;
        }

        return '"'.$value.'"';
    }

    /**
     * @param  int[]  $distributorIds
     * @return array<int, int> distributor_id → total personal BV paise
     */
    private function batchPersonalBvPaise(array $distributorIds): array
    {
        if ($distributorIds === []) {
            return [];
        }

        return DB::table('bv_ledger_entries')
            ->whereIn('distributor_id', $distributorIds)
            ->where('type', 'accrual')
            ->groupBy('distributor_id')
            ->pluck(DB::raw('SUM(bv_paise)'), 'distributor_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
