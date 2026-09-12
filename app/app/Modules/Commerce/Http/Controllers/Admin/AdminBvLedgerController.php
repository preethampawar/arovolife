<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Commerce\Support\Bv;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Support\ReportExport;
use Generator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function export(Request $request): StreamedResponse
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
        $q = is_string($q) ? $q : null;

        if ($tab === 'summary') {
            // Grouped-aggregate query, so the row count is bounded by distinct
            // distributors, not ledger entries — but it can still be tens of
            // thousands of rows, so this is streamed via cursor() the same as
            // the entries branch rather than materialised with ->get().
            $count = (int) DB::query()->fromSub($this->summaryQuery($from, $to, $q), 't')->count();
            $columns = [
                ['key' => 'adn', 'label' => 'ADN'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'accrued', 'label' => 'Accrued BV'],
                ['key' => 'reversed', 'label' => 'Reversed BV'],
                ['key' => 'net', 'label' => 'Net BV'],
                ['key' => 'orders', 'label' => 'Orders'],
                ['key' => 'last_activity', 'label' => 'Last Activity'],
            ];
            $rows = $this->summaryRows($from, $to, $q);
        } else {
            $count = (int) BvLedgerEntry::query()->dateRange($from, $to)->count();
            $columns = [
                ['key' => 'effective_at', 'label' => 'Effective At'],
                ['key' => 'adn', 'label' => 'ADN'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'order_no', 'label' => 'Order No'],
                ['key' => 'type', 'label' => 'Type'],
                ['key' => 'bv', 'label' => 'BV'],
            ];
            $rows = $this->entriesRows($from, $to);
        }

        $this->auditExport($tab, $count, $from, $to, $q);

        return ReportExport::respond($request, "bv-ledger-{$tab}", $columns, $rows);
    }

    public function exportShow(Distributor $distributor, Request $request): StreamedResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        [$from, $to] = $this->dateRange($request);

        $count = (int) BvLedgerEntry::query()->forDistributor($distributor->id)->dateRange($from, $to)->count();

        $columns = [
            ['key' => 'effective_at', 'label' => 'Effective At'],
            ['key' => 'order_no', 'label' => 'Order No'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'bv', 'label' => 'BV'],
            ['key' => 'running_balance', 'label' => 'Running Balance BV'],
        ];

        $this->auditExport("distributor:{$distributor->adn}", $count, $from, $to);

        return ReportExport::respond(
            $request,
            "bv-ledger-{$distributor->adn}",
            $columns,
            $this->exportShowRows($distributor->id, $from, $to),
        );
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
     * Streams the summary tab row-by-row (S6b: lazy/cursor iteration instead
     * of ->get(), so this no longer hydrates Eloquent models or builds an
     * in-memory CSV/XLSX document for the whole ledger regardless of how many
     * distributors have accrued BV). Note this is a constant-factor
     * improvement, not an unbounded-safe stream: the mysql PDO connection
     * still buffers the result set (MYSQL_ATTR_USE_BUFFERED_QUERY is on), so
     * a multi-million-row export retains a memory ceiling from buffering
     * alone — see docs/compliance/risk-register.md R-84.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function summaryRows(?Carbon $from, ?Carbon $to, ?string $q): Generator
    {
        foreach ($this->summaryQuery($from, $to, $q)->cursor() as $r) {
            yield [
                'adn' => $r->adn,
                'name' => $r->full_name,
                'accrued' => Bv::points((int) $r->accrued),
                'reversed' => Bv::points((int) $r->reversed),
                'net' => Bv::points((int) $r->net),
                'orders' => (int) $r->orders,
                'last_activity' => $r->last_at,
            ];
        }
    }

    /**
     * The raw chronological feed, streamed via cursor() so the row count —
     * unbounded, one entry per accrual/reversal across every distributor —
     * never has to fit in memory (S6b). Distributor and order identity are
     * pulled in via join rather than ->with(), because cursor() does not
     * eager-load relations and would otherwise N+1 per row.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function entriesRows(?Carbon $from, ?Carbon $to): Generator
    {
        $query = BvLedgerEntry::query()
            ->dateRange($from, $to)
            ->join('distributors', 'distributors.id', '=', 'bv_ledger_entries.distributor_id')
            ->join('users', 'users.id', '=', 'distributors.user_id')
            ->leftJoin('orders', 'orders.id', '=', 'bv_ledger_entries.order_id')
            ->orderByDesc('bv_ledger_entries.effective_at')
            ->orderByDesc('bv_ledger_entries.id')
            ->select([
                'bv_ledger_entries.effective_at',
                'bv_ledger_entries.type',
                'bv_ledger_entries.bv_paise',
                'distributors.adn',
                'users.full_name',
                'orders.order_no',
            ])
            ->toBase();

        foreach ($query->cursor() as $e) {
            yield [
                'effective_at' => $e->effective_at,
                'adn' => $e->adn,
                'name' => $e->full_name,
                'order_no' => $e->order_no,
                'type' => $e->type,
                'bv' => Bv::points((int) $e->bv_paise),
            ];
        }
    }

    /**
     * Per-distributor feed for exportShow(). Must preserve the query's own
     * effective_at/id order exactly — the running balance is only correct
     * in-order — so this uses cursor() rather than lazy(), which chunks (and
     * therefore reorders) by primary key.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function exportShowRows(int $distributorId, ?Carbon $from, ?Carbon $to): Generator
    {
        $query = BvLedgerEntry::query()
            ->forDistributor($distributorId)
            ->dateRange($from, $to)
            ->leftJoin('orders', 'orders.id', '=', 'bv_ledger_entries.order_id')
            ->orderBy('bv_ledger_entries.effective_at')
            ->orderBy('bv_ledger_entries.id')
            ->select([
                'bv_ledger_entries.effective_at',
                'bv_ledger_entries.type',
                'bv_ledger_entries.bv_paise',
                'orders.order_no',
            ])
            ->toBase();

        $running = 0;

        foreach ($query->cursor() as $e) {
            $running += (int) $e->bv_paise;

            yield [
                'effective_at' => $e->effective_at,
                'order_no' => $e->order_no,
                'type' => $e->type,
                'bv' => Bv::points((int) $e->bv_paise),
                'running_balance' => Bv::points($running),
            ];
        }
    }

    /**
     * S6b: row_count is a count() taken immediately before the cursor-backed
     * stream starts, not a count of rows actually written — the ledger is
     * append-only, so a row written between the count and the stream (or a
     * client that aborts the download mid-stream) can make the true streamed
     * count differ from what's logged here. 'row_count_basis' records that
     * so a reviewer reading this audit row later doesn't take row_count as an
     * exact post-hoc tally.
     */
    private function auditExport(string $scope, int $rowCount, ?Carbon $from, ?Carbon $to, ?string $search = null): void
    {
        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'bv.report.exported',
            'subject_type' => 'system',
            'subject_id' => null,
            'before_hash' => null,
            'after_hash' => AuditDigests::of([
                'scope' => $scope,
                'row_count' => $rowCount,
                'row_count_basis' => 'pre_stream',
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'search' => $search,
            ]),
            'details' => [
                'scope' => $scope,
                'row_count' => $rowCount,
                'row_count_basis' => 'pre_stream',
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'search' => $search,
            ],
            'ip' => request()->ip(),
        ]);
    }
}
