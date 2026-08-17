<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers\Admin;

use App\Modules\Analytics\Services\FunnelAnalytics;
use App\Modules\Analytics\Services\RetentionAnalytics;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The analytics dashboard.
 *
 * Admin-facing and historical throughout. Nothing on this page projects,
 * extrapolates or averages an income — a chart that forecasts is a chart
 * somebody will eventually put in front of a prospect, and DSR Rule 5(1)(d)
 * does not care that it was built for internal use.
 */
final class AdminAnalyticsController extends Controller
{
    private const RETENTION_MONTHS = 12;

    /**
     * The widest window the page will report on.
     *
     * Every panel runs aggregates over orders and the BV ledger, and
     * `topByVolume` additionally issues a per-row order count and a
     * closure-table team count. An unbounded window is a table scan anyone
     * with an admin login can trigger by editing the query string, repeatedly
     * (T-6.1 finding M-9). Two years covers any reporting question this page
     * is for; anything wider belongs in a warehouse, not a request.
     */
    private const MAX_WINDOW_DAYS = 730;

    public function __construct(
        private readonly FunnelAnalytics $funnels,
        private readonly RetentionAnalytics $retention,
    ) {}

    public function index(Request $request): View
    {
        [$from, $to] = $this->resolveWindow($request);

        return view('admin.analytics.index', [
            'from' => $from,
            'to' => $to,
            'registrationFunnel' => $this->funnels->registration($from, $to),
            'commerceFunnel' => $this->funnels->commerce($from, $to),
            'totals' => $this->funnels->commerceTotals($from, $to),
            'retention' => $this->retention->monthlyRetention(self::RETENTION_MONTHS, $to),
            'baseShape' => $this->retention->baseShape($to),
            'topByVolume' => $this->retention->topByVolume($from, $to),
        ]);
    }

    /**
     * The reporting window. Defaults to the last 30 days.
     *
     * A malformed date silently falls back rather than throwing: a mistyped
     * URL should not 500 a dashboard.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(Request $request): array
    {
        $to = $this->parseDate((string) $request->query('to', '')) ?? Carbon::now();
        $from = $this->parseDate((string) $request->query('from', '')) ?? $to->copy()->subDays(30);

        // A window that runs backwards produces empty tables with no
        // explanation, so swap rather than confuse.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        // Clamp rather than reject: a too-wide window is far more often a
        // mistyped year than an attack, and an error page teaches nobody
        // anything. The view states the window it actually used.
        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            $from = $to->copy()->subDays(self::MAX_WINDOW_DAYS);
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    private function parseDate(string $raw): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $raw) ?: null;
    }
}
