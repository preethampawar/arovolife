<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\ActionCenter\Services\ActionCenterService;
use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\SalesReportService;
use App\Modules\Commerce\Support\SalesScope;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\DTOs\EngineHealthReport;
use App\Modules\Compensation\Services\EngineHealthService;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventorySettings;
use App\Modules\Inventory\Services\StockValuationService;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds one payload per dashboard panel.
 *
 * Every figure here is read from the service that already owns it —
 * `SalesReportService` decides what counts as a sale, `InventoryAlertService`
 * decides what counts as low stock, `EngineHealthService` decides what counts
 * as an unhealthy engine. Nothing is re-derived. A dashboard that keeps its own
 * definition of a number eventually disagrees with the report it summarises,
 * and the dashboard is the version people believe.
 *
 * **Caching.** 60 seconds, the same window the sidebar badges use, and keyed
 * *without* the viewer: the permission check happens in the controller before
 * we are called, so every viewer who reaches a given panel is entitled to
 * identical content and a per-user key would just multiply the entries. The
 * one exception is `attention()`, whose content genuinely differs per viewer —
 * and that one is already cached by `ActionCenterService`, so it is not cached
 * again here; wrapping a 60s cache in another 60s cache would double the
 * staleness window for no gain.
 *
 * Each payload carries `generated_at` from inside the cached closure, so the
 * "as of" stamp the panel renders reports when the numbers were computed
 * rather than when the HTML was built.
 *
 * **Cached payloads are arrays and scalars only — never objects.** See
 * `remember()` below for why; it is not a style preference, it is the
 * difference between a working panel and a 500.
 *
 * Nothing here projects, forecasts or annualises: hard rule 3 forbids income
 * projections, and a dashboard is exactly where one would be tempting.
 */
final class DashboardPanelData
{
    private const TTL_SECONDS = 60;

    public function __construct(
        private readonly SalesReportService $sales,
        private readonly InventoryAlertService $inventoryAlerts,
        private readonly InventorySettings $inventorySettings,
        private readonly StockValuationService $stockValuation,
        private readonly ActionCenterService $actionCenter,
        private readonly EngineHealthService $engineHealth,
        private readonly WalletService $wallet,
        private readonly PayoutService $payouts,
    ) {}

    /**
     * `Cache::remember`, restricted to what this application's cache can
     * actually hand back.
     *
     * `config/cache.php` sets `serializable_classes => false` — a deliberate
     * hardening choice, so that a leaked `APP_KEY` cannot be turned into a
     * gadget chain through the cache. The consequence is absolute: the store
     * calls `unserialize($value, ['allowed_classes' => false])`, so **every
     * object in a cached payload comes back as `__PHP_Incomplete_Class`**. A
     * Carbon, an Eloquent model, a `Collection`, a readonly DTO — all of them.
     *
     * There is no error on the way in and no null on the way out. The write
     * succeeds, the read succeeds, and the panel dies on the first method call
     * against the husk: *"tried to call a method on an incomplete object"*, as
     * a 500 inside the fragment. Nor will the test suite catch it, because the
     * test environment's cache store is `array`, which never serialises
     * anything — this is only reachable against a real store.
     *
     * So every closure below returns arrays and scalars. `generated_at` goes
     * in as a Unix timestamp and is rebuilt here; a panel that needs a richer
     * object rebuilds it from cached scalars after this has returned.
     *
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function remember(string $key, Closure $build): array
    {
        /** @var array<string, mixed> $payload */
        $payload = Cache::remember($key, self::TTL_SECONDS, $build);

        $payload['generated_at'] = Carbon::createFromTimestamp(
            (int) $payload['generated_at'],
            config('app.timezone'),
        );

        return $payload;
    }

    /**
     * The Action Center summary, grouped, exactly as that service returns it.
     *
     * Not re-cached and not reshaped: `ActionCenterService` already caches per
     * user for 60s, already filters every provider by the viewer's own
     * permission, and already excludes snoozed items. A group with nothing
     * outstanding comes back with zero rows — render nothing for it, because
     * silence means nothing to do.
     *
     * @return array{groups: Collection<string, array<int, array<string, mixed>>>, generated_at: Carbon}
     */
    public function attention(User $user): array
    {
        return [
            'groups' => $this->actionCenter->summary($user),
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Sales for today, the last 7 days and the month so far.
     *
     * On the ORDER date, not the ship date. `SalesReportService` defaults to
     * `BASIS_SHIPPED` because the profit report stamps cost at pack time, but
     * "what came in today" is a question about when the order was placed.
     *
     * Revenue is `gross_ex_gst_paise`, which the service already computes net
     * of GST — do not subtract `gst_paise` from it a second time. `cash_paise`
     * is what the buyer actually handed over (GST, shipping and collection fee
     * included) and is labelled "collected" rather than presented as revenue.
     * Refunds are reported as their own line and never netted silently into
     * the figures above them.
     *
     * Shape: `windows` keyed `today` / `week` / `month`, each with `label`,
     * `totals`, `refunds` and `bv_paise`; plus `generated_at`. Declared as the
     * open array the six panels share rather than a literal shape, because
     * `remember()` is generic over all of them and swaps `generated_at` from
     * the timestamp it cached to the Carbon the view renders.
     *
     * @return array<string, mixed>
     */
    public function sales(): array
    {
        return $this->remember('admin.dashboard.sales', function (): array {
            $now = Carbon::now();

            $windows = [
                'today' => ['label' => 'Today', 'from' => $now->copy()->startOfDay()],
                'week' => ['label' => 'Last 7 days', 'from' => $now->copy()->subDays(6)->startOfDay()],
                'month' => ['label' => 'This month', 'from' => $now->copy()->startOfMonth()],
            ];

            $scope = SalesScope::all();

            foreach ($windows as $key => $window) {
                $from = $window['from'];

                $totals = $this->sales->totals($scope, $from, $now, SalesReportService::BASIS_ORDERED);
                $refunds = $this->sales->refundTotals($scope, $from, $now);

                $windows[$key]['totals'] = $totals;
                $windows[$key]['refunds'] = $refunds;
                $windows[$key]['bv_paise'] = (int) BvLedgerEntry::query()
                    ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
                    ->whereBetween('effective_at', [$from, $now])
                    ->sum('bv_paise');

                // The window bound was only ever an argument to the calls
                // above. Nothing renders it, and a Carbon cannot survive the
                // cache (see `remember()`), so it does not go in.
                unset($windows[$key]['from']);
            }

            return ['windows' => $windows, 'generated_at' => Carbon::now()->getTimestamp()];
        });
    }

    /**
     * How many orders sit at each stage of the lifecycle.
     *
     * One grouped query. This is *state*, not a queue: the Action Center's
     * `orders.paid_not_packed` counts orders that have been waiting past their
     * SLA, which is a different and smaller number by design. Both are
     * legitimate and the panels label them differently so nobody reads one as
     * the other.
     *
     * Zero-count stages still render — a pipeline with a gap in it is the
     * information. The exception statuses render only when something is in
     * them.
     *
     * Shape: `pipeline` and `exceptions` as status => count maps, their two
     * totals, and `generated_at`. Open array for the reason given on
     * `sales()`.
     *
     * @return array<string, mixed>
     */
    public function orders(): array
    {
        return $this->remember('admin.dashboard.orders', function (): array {
            /** @var array<string, int> $counts */
            $counts = DB::table('orders')
                ->selectRaw('status, COUNT(*) as order_count')
                ->groupBy('status')
                ->pluck('order_count', 'status')
                ->map(fn ($n): int => (int) $n)
                ->all();

            $pipeline = [];

            foreach ([
                Order::STATUS_PLACED,
                Order::STATUS_PAID,
                Order::STATUS_READY_TO_SHIP,
                Order::STATUS_SHIPPED,
                Order::STATUS_AWAITING_COLLECTION,
                Order::STATUS_DELIVERED,
                Order::STATUS_CONFIRMED,
            ] as $status) {
                $pipeline[$status] = $counts[$status] ?? 0;
            }

            $exceptions = [];

            foreach ([
                Order::STATUS_CANCELLED,
                Order::STATUS_REFUND_REQUESTED,
                Order::STATUS_REFUND_INSPECTION,
                Order::STATUS_REFUND_APPROVED,
                Order::STATUS_REFUNDED,
            ] as $status) {
                $count = $counts[$status] ?? 0;

                if ($count > 0) {
                    $exceptions[$status] = $count;
                }
            }

            return [
                'pipeline' => $pipeline,
                'exceptions' => $exceptions,
                'pipeline_total' => array_sum($pipeline),
                'exceptions_total' => array_sum($exceptions),
                'generated_at' => Carbon::now()->getTimestamp(),
            ];
        });
    }

    /**
     * What is at risk in the warehouses and what the stock is worth.
     *
     * `currentValuePaise()` and never `valueAtPaise()`: the latter replays the
     * whole append-only `stock_movements` ledger up to a date, and no index
     * serves that scan. It is right for a valuation report and wrong for
     * anything that renders on every dashboard visit.
     *
     * @return array<string, mixed>
     */
    public function inventory(): array
    {
        return $this->remember('admin.dashboard.inventory', fn (): array => [
            'low_stock' => $this->inventoryAlerts->lowStock()->count(),
            'expiring' => $this->inventoryAlerts->expiring($this->inventorySettings->expiryAlertDays())->count(),
            'expired' => $this->inventoryAlerts->expired()->count(),
            'expiry_days' => $this->inventorySettings->expiryAlertDays(),
            'stock_value_paise' => $this->stockValuation->currentValuePaise(),
            'warehouses' => Warehouse::query()->where('status', Warehouse::STATUS_ACTIVE)->count(),
            'open_purchase_orders' => PurchaseOrder::query()
                ->whereIn('status', [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIALLY_RECEIVED])
                ->count(),
            'transfers_in_transit' => StockTransfer::query()
                ->where('status', StockTransfer::STATUS_DISPATCHED)
                ->count(),
            'generated_at' => Carbon::now()->getTimestamp(),
        ]);
    }

    /**
     * What the plan cost this month, and what is owed out.
     *
     * `commissionTotalsByType()` is company-wide and already restricted to
     * `WalletService::BONUS_CREDIT_TYPES`. The repurchase-pot types are
     * deliberately absent from that list and must stay absent: adding them
     * back counts the same rupee twice.
     *
     * Held money is the four `PayoutLineItem::HELD_STATUSES` — income that
     * accrued and that nothing has debited, because the distributor has no
     * bank account on file, is web-only, is awaiting KYC, or whose bank
     * details would not decrypt. It sits in wallets, so it belongs on a page
     * about money owed.
     *
     * Liveness of a processing batch comes from
     * `PayoutService::batchLastSignOfLife()`. `payout_batches.updated_at` is
     * written exactly twice per sweep, so it records when the sweep *started*;
     * the per-distributor line-item writes are the actual heartbeat, and that
     * method is public precisely so nothing re-derives it wrong.
     *
     * @return array<string, mixed>
     */
    public function money(): array
    {
        $payload = $this->remember('admin.dashboard.money', function (): array {
            $now = Carbon::now();

            /** @var PayoutBatch|null $latest */
            $latest = PayoutBatch::query()->orderByDesc('batch_date')->orderByDesc('id')->first();

            $stuckSince = null;

            if ($latest !== null && $latest->status === PayoutBatch::STATUS_PROCESSING) {
                $lastSign = $this->payouts->batchLastSignOfLife($latest);

                if ($lastSign !== null && $now->diffInSeconds($lastSign, true) >= PayoutService::STUCK_BATCH_MIN_IDLE_SECONDS) {
                    $stuckSince = $lastSign;
                }
            }

            // Aliased, not `pluck(DB::raw('SUM(gross_paise)'))`: that form only
            // works while the driver happens to name the result column exactly
            // as written, and when it does not, `(int) null` renders held money
            // as ₹0 — a money figure silently wrong instead of loudly broken.
            /** @var array<string, int> $held */
            $held = PayoutLineItem::query()
                ->selectRaw('status, SUM(gross_paise) as held_paise')
                ->whereIn('status', PayoutLineItem::HELD_STATUSES)
                ->groupBy('status')
                ->pluck('held_paise', 'status')
                ->map(fn ($n): int => (int) $n)
                ->all();

            return [
                // The five columns the panel renders, not the model. An
                // Eloquent model in the cache comes back as an incomplete
                // object (see `remember()`), and the panel needs no more
                // than this.
                'latest_batch' => $latest === null ? null : [
                    'batch_type' => (string) $latest->batch_type,
                    'batch_date' => $latest->batch_date->toDateString(),
                    'status' => (string) $latest->status,
                    'distributor_count' => (int) $latest->distributor_count,
                    'total_net_paise' => (int) $latest->total_net_paise,
                ],
                'stuck_since' => $stuckSince?->getTimestamp(),
                'awaiting_approval' => PayoutBatch::query()
                    ->where('status', PayoutBatch::STATUS_PENDING)
                    ->count(),
                'held' => $held,
                'held_total_paise' => array_sum($held),
                'commission' => $this->wallet->commissionTotalsByType($now->copy()->startOfMonth(), $now),
                'commission_labels' => WalletLedgerEntry::typeLabels(),
                'generated_at' => Carbon::now()->getTimestamp(),
            ];
        });

        // Rebuilt on the way out, so the view still gets to say
        // `->diffForHumans()` about a real instant.
        $payload['stuck_since'] = $payload['stuck_since'] === null
            ? null
            : Carbon::createFromTimestamp((int) $payload['stuck_since'], config('app.timezone'));

        return $payload;
    }

    /**
     * Whether the compensation engines actually ran.
     *
     * The same `EngineHealthService::report()` call that
     * `Platform\EngineRunsFailedProvider::count()` makes, so this panel and
     * the Action Center's platform row can never disagree about how many
     * things are wrong.
     *
     * @return array{report: EngineHealthReport, generated_at: Carbon}
     */
    public function engines(): array
    {
        $payload = $this->remember('admin.dashboard.engines', function (): array {
            $report = $this->engineHealth->report(Carbon::now());

            // The DTO is readonly and holds nothing but lists of scalars, so
            // it survives the round trip as those five lists and is rebuilt
            // below. Caching the object itself would not (see `remember()`).
            return [
                'failures' => $report->failures,
                'missing' => $report->missing,
                'stuck' => $report->stuck,
                'premature_freezes' => $report->prematureFreezes,
                'chain_alerts' => $report->chainAlerts,
                'generated_at' => Carbon::now()->getTimestamp(),
            ];
        });

        return [
            'report' => new EngineHealthReport(
                failures: $payload['failures'],
                missing: $payload['missing'],
                stuck: $payload['stuck'],
                prematureFreezes: $payload['premature_freezes'],
                chainAlerts: $payload['chain_alerts'],
            ),
            'generated_at' => $payload['generated_at'],
        ];
    }

    /**
     * Who is in the network, and who just joined.
     *
     * Every count joins `distributors`, never `users` alone. A bare
     * `users.status = 'pending'` count includes legacy orphan accounts left by
     * the registration wizard before it moved to session-only user creation —
     * that is the tile that read "11 pending" while the KYC queue held one,
     * and the join is the fix. The cooling-off figures additionally require
     * `users.status = 'active'` so a terminated distributor whose timer has
     * not run out does not inflate them.
     *
     * @return array<string, mixed>
     */
    public function people(): array
    {
        return $this->remember('admin.dashboard.people', function (): array {
            $now = Carbon::now();

            $base = fn (): Builder => DB::table('distributors')
                ->join('users', 'distributors.user_id', '=', 'users.id');

            return [
                'active' => $base()->where('users.status', 'active')->count(),
                'pending' => $base()->where('users.status', 'pending')->count(),
                'frozen' => $base()->where('users.status', 'frozen')->count(),
                'joined_this_month' => $base()
                    ->where('distributors.effective_date', '>=', $now->copy()->startOfMonth())
                    ->count(),
                'cooling_off_active' => $base()
                    ->where('users.status', 'active')
                    ->where('distributors.cooling_off_end_at', '>', $now)
                    ->count(),
                'cooling_off_expiring' => $base()
                    ->where('users.status', 'active')
                    ->where('distributors.cooling_off_end_at', '>', $now)
                    ->where('distributors.cooling_off_end_at', '<=', $now->copy()->addDays(7))
                    ->count(),
                // No email column. This list is cached for 60 seconds in a Redis
                // shared with eight other apps (ADR-0011), and the ADN above the
                // name already identifies the row for anyone who needs to open
                // it — caching a contact address to use as a fallback label is
                // more personal data at rest than the panel earns.
                'latest' => $base()
                    ->select(
                        'distributors.id',
                        'distributors.adn',
                        'distributors.effective_date',
                        'users.full_name',
                        'users.status',
                    )
                    ->orderByDesc('distributors.id')
                    ->limit(8)
                    ->get()
                    // Plain arrays. `get()` hands back a Collection of
                    // stdClass, and neither survives the cache — see
                    // `remember()`. The view reads `$row['adn']`.
                    ->map(fn (object $row): array => (array) $row)
                    ->all(),
                'generated_at' => Carbon::now()->getTimestamp(),
            ];
        });
    }
}
