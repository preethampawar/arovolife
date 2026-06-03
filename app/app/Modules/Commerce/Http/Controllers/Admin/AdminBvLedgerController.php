<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Commerce\Support\Bv;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin BV-ledger report (ADR-0006). Read-only reporting over the append-only
 * `bv_ledger_entries` store, for admins only (the route group enforces
 * role:admin). BV here is always sale-linked — every entry references an
 * order_id (hard rule #2) — and the views surface that order, so the report
 * reinforces the "no BV without a sale" invariant rather than implying any
 * earnings (hard rule #3 concerns distributor-facing projections, not admin
 * back-office reporting).
 *
 * Single-source-of-truth: the effective-date window lives in
 * {@see BvLedgerEntry::scopeDateRange()}, BV display/formatting in
 * {@see Bv}, and per-distributor accrued/reversed/net in
 * {@see BvLedgerService}. This controller only composes them.
 */
final class AdminBvLedgerController extends Controller
{
    private const ENTRIES_PER_PAGE = 50;

    private const SUMMARY_PER_PAGE = 25;

    public function __construct(private readonly BvLedgerService $bvLedger) {}

    public function index(Request $request): View
    {
        $request->validate([
            'tab' => ['nullable', 'in:summary,entries'],
            'q' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $tab = $request->query('tab') === 'entries' ? 'entries' : 'summary';
        [$from, $to] = $this->dateRange($request);

        // Date-scoped headline cards (cheap aggregates over the whole ledger).
        $cardBase = BvLedgerEntry::query()->dateRange($from, $to);
        $cards = [
            'net' => (int) (clone $cardBase)->sum('bv_paise'),
            'accrued' => (int) (clone $cardBase)->where('type', BvLedgerEntry::TYPE_ACCRUAL)->sum('bv_paise'),
            'reversed' => (int) (clone $cardBase)->where('type', BvLedgerEntry::TYPE_REVERSAL)->sum('bv_paise'),
            'distributors' => (int) (clone $cardBase)->distinct()->count('distributor_id'),
            'entries' => (int) (clone $cardBase)->count(),
        ];

        $summary = null;
        $entries = null;

        if ($tab === 'summary') {
            $summary = $this->summaryQuery($from, $to, $request->query('q'))
                ->paginate(self::SUMMARY_PER_PAGE)
                ->withQueryString();
        } else {
            $entries = BvLedgerEntry::query()
                ->with(['distributor.user', 'order'])
                ->dateRange($from, $to)
                ->orderByDesc('effective_at')
                ->orderByDesc('id')
                ->paginate(self::ENTRIES_PER_PAGE)
                ->withQueryString();
        }

        return view('admin.commerce.bv-ledger.index', [
            'tab' => $tab,
            'cards' => $cards,
            'summary' => $summary,
            'entries' => $entries,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'q' => $request->query('q'),
        ]);
    }

    public function show(Distributor $distributor, Request $request): View
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $distributor->loadMissing('user');
        [$from, $to] = $this->dateRange($request);

        $ordered = BvLedgerEntry::query()
            ->forDistributor($distributor->id)
            ->dateRange($from, $to)
            ->with('order')
            ->orderBy('effective_at')
            ->orderBy('id');

        $page = max(1, (int) $request->query('page', '1'));
        $offset = ($page - 1) * self::ENTRIES_PER_PAGE;

        // Opening balance for this page = sum of the BV on every entry that
        // sorts before the first row shown here, so the running balance is
        // correct across pagination. LIMIT 0 (page 1) yields an empty set → 0.
        $openingBalance = (int) DB::query()
            ->fromSub((clone $ordered)->select('bv_paise')->limit($offset), 't')
            ->sum('bv_paise');

        return view('admin.commerce.bv-ledger.show', [
            'distributor' => $distributor,
            'entries' => (clone $ordered)->paginate(self::ENTRIES_PER_PAGE)->withQueryString(),
            'openingBalance' => $openingBalance,
            'lifetimeNet' => $this->bvLedger->totalPersonalBvPaise($distributor->id),
            'breakdown' => $this->bvLedger->breakdownForDistributor($distributor->id, $from, $to),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);
    }

    public function export(Request $request): Response
    {
        $request->validate([
            'tab' => ['nullable', 'in:summary,entries'],
            'q' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $tab = $request->query('tab') === 'entries' ? 'entries' : 'summary';
        [$from, $to] = $this->dateRange($request);

        $q = $request->query('q');

        if ($tab === 'summary') {
            $rows = $this->summaryQuery($from, $to, $q)->get();
            $csv = "ADN,Name,Accrued BV,Reversed BV,Net BV,Orders,Last Activity\n";
            foreach ($rows as $r) {
                $csv .= $this->csvRow([
                    $r->adn, $r->full_name,
                    Bv::points((int) $r->accrued), Bv::points((int) $r->reversed), Bv::points((int) $r->net),
                    $r->orders, $r->last_at,
                ]);
            }
        } else {
            $rows = BvLedgerEntry::query()
                ->with(['distributor.user', 'order'])
                ->dateRange($from, $to)
                ->orderByDesc('effective_at')
                ->orderByDesc('id')
                ->get();
            $csv = "Effective At,ADN,Name,Order No,Type,BV\n";
            foreach ($rows as $e) {
                $csv .= $this->csvRow([
                    $e->effective_at, $e->distributor?->adn, $e->distributor?->user?->full_name,
                    $e->order?->order_no, $e->type, Bv::points($e->bv_paise),
                ]);
            }
        }

        $this->auditExport($tab, $rows->count(), $from, $to, is_string($q) ? $q : null);

        return $this->csvResponse($csv, "bv-ledger-{$tab}");
    }

    public function exportShow(Distributor $distributor, Request $request): Response
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        [$from, $to] = $this->dateRange($request);

        $rows = BvLedgerEntry::query()
            ->forDistributor($distributor->id)
            ->dateRange($from, $to)
            ->with('order')
            ->orderBy('effective_at')
            ->orderBy('id')
            ->get();

        $running = 0;
        $csv = "Effective At,Order No,Type,BV,Running Balance BV\n";
        foreach ($rows as $e) {
            $running += $e->bv_paise;
            $csv .= $this->csvRow([
                $e->effective_at, $e->order?->order_no, $e->type, Bv::points($e->bv_paise), Bv::points($running),
            ]);
        }

        $this->auditExport("distributor:{$distributor->adn}", $rows->count(), $from, $to);

        return $this->csvResponse($csv, "bv-ledger-{$distributor->adn}");
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Per-distributor BV aggregates, highest-net first, as the underlying query
     * builder (stdClass rows). Grouped on the distributor identity columns to
     * satisfy ONLY_FULL_GROUP_BY. Date filtering goes through the model's
     * dateRange scope (single source of truth).
     */
    private function summaryQuery(?Carbon $from, ?Carbon $to, ?string $q): QueryBuilder
    {
        return BvLedgerEntry::query()
            ->dateRange($from, $to)
            ->join('distributors', 'distributors.id', '=', 'bv_ledger_entries.distributor_id')
            ->join('users', 'users.id', '=', 'distributors.user_id')
            ->when(is_string($q) && $q !== '', fn (Builder $w) => $w->where(function (Builder $x) use ($q): void {
                $x->where('distributors.adn', 'like', "%{$q}%")
                    ->orWhere('users.full_name', 'like', "%{$q}%");
            }))
            ->groupBy('distributors.id', 'distributors.adn', 'users.full_name')
            ->selectRaw('distributors.id as distributor_id, distributors.adn, users.full_name')
            ->selectRaw('SUM(bv_ledger_entries.bv_paise) as net')
            ->selectRaw("SUM(CASE WHEN bv_ledger_entries.type = 'accrual' THEN bv_ledger_entries.bv_paise ELSE 0 END) as accrued")
            ->selectRaw("SUM(CASE WHEN bv_ledger_entries.type = 'reversal' THEN bv_ledger_entries.bv_paise ELSE 0 END) as reversed")
            ->selectRaw('COUNT(DISTINCT bv_ledger_entries.order_id) as orders')
            ->selectRaw('MAX(bv_ledger_entries.effective_at) as last_at')
            ->orderByDesc('net')
            ->toBase();
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function dateRange(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');

        return [
            is_string($from) && $from !== '' ? Carbon::parse($from)->startOfDay() : null,
            is_string($to) && $to !== '' ? Carbon::parse($to)->endOfDay() : null,
        ];
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function csvRow(array $cells): string
    {
        return implode(',', array_map(
            static fn ($v): string => '"'.str_replace('"', '""', (string) ($v ?? '')).'"',
            $cells,
        ))."\n";
    }

    private function csvResponse(string $csv, string $namePrefix): Response
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$namePrefix.'-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    private function auditExport(string $scope, int $rowCount, ?Carbon $from, ?Carbon $to, ?string $search = null): void
    {
        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'bv.report.exported',
            'subject_type' => 'system',
            'subject_id' => null,
            'details' => [
                'scope' => $scope,
                'row_count' => $rowCount,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'search' => $search,
            ],
            'ip' => request()->ip(),
        ]);
    }
}
