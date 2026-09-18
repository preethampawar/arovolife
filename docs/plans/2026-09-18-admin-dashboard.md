# Admin Dashboard — Lazy Panels, Business-Owner Snapshot

**Date:** 2026-09-18 · **Status:** awaiting approval
**Repo root for all paths below:** `arovolife-code/app/` (the Laravel root), except
`docs/` paths which are relative to `arovolife-code/`.

---

## Goal

`/admin` renders with **zero data queries**. The page paints immediately as a
titled skeleton, and each panel fetches its own rendered HTML fragment — the
ones above the fold on load, the rest when they scroll into view. What the
panels say is the current state of the business a business owner would want at
a glance: what sold, what is stuck in fulfilment, what stock is at risk and
what it is worth, what the plan cost and what is owed out, whether the
compensation engines ran, and who joined. The Recent Audit Events list is gone,
as are the tiles that counted things nobody acts on.

Every number comes from the service that already owns it. The dashboard invents
no figure and keeps no table.

## Non-goals

- **No trends, no charts, no date-range picker.** Admin → Analytics already owns
  windowed analysis (registration funnel, commerce funnel, retention, headline
  totals for a range). This page answers *what is true right now*; where a
  number invites a "why", the panel links to Analytics or to the owning report.
- **No new permissions.** Panels gate on the permission that already gates their
  source data.
- **No new tables, no new columns.** One index, no schema change.
- **No polling / auto-refresh.** Manual refresh per panel only (see D8).
- **No anticipation of the order-fulfilment rework.** `docs/plans/2026-09-18-order-fulfilment.md`
  is planned, unbuilt and currently a compliance FAIL. The order panel reads the
  statuses that exist **today**; when that plan lands it gains states. Named as
  a dependency, deliberately not pre-built.
- **No Livewire / Alpine / Inertia.** The app has none; this does not introduce one.

---

## Current behaviour, and what is wrong with it

`Admin\Http\Controllers\AdminDashboardController::index()` computes everything
eagerly on every visit:

| What | Cost |
|---|---|
| 7 `DB::table()->count()` stat tiles | 7 queries |
| `audit_log` + `users` join, 10 rows, `AuditLogPresenter::warmCaches()` + `present()` per row | 1 query + presenter batch lookups |
| `distributors` + `users` join, 8 rows | 1 query |
| `InventoryAlertService` low / expiring / expired | 3 queries |
| `ActionCenterService::oldestCriticalItems($user, 5)` | bounded fan-out (60s cached summary) |

Beyond the cost, four things on the page are not worth their space:

1. **Recent Audit Events** — removal requested. It is a raw event feed; the Audit
   Log page exists and is one sidebar click away.
2. **Audit Events Today** tile — a count of system activity that nobody acts on.
   With the list gone it links to a page the number does not summarise.
3. **Total Users** — counts `users` rows: staff accounts, and the legacy orphan
   accounts the controller's own comment records as having misled operators
   ("the tile read 11 pending while the KYC queue only showed 1"). The honest
   denominator is distributors.
4. **Recent Registrations** as its own half-width card — useful content, wrong
   weight. It becomes a compact list inside the Network panel.

---

## Architecture decisions

**D1 — Panels return rendered HTML fragments, not JSON.**
Alternative: JSON + client-side templating. Rejected: it would duplicate
`IndianNumber` formatting in JavaScript (the project rule is that every displayed
number goes through it), duplicate markup that Blade already owns, and ship
permission-scoped content to a browser that should never receive it. With
fragments the loader is ~45 lines and entirely generic.

**D2 — The loader is an inline `@push('scripts')` block, not a new Vite entry.**
Alternative: `resources/js/admin-dashboard.js`. Rejected on deploy mechanics:
the staging server's Node is too old to build Vite, so every asset-entry change
forces a local build plus an rsync of `public/build`
(`docs/plans` history and the staging deploy notes both record this). An inline
script ships with the Blade on a plain git pull. It also matches the existing
precedent at `resources/views/admin/compensation/engine-runs/index.blade.php:434`.

**D3 — The shell issues zero queries.**
`index()` returns a view with no data whatsoever. The only per-panel work done
server-side before any fetch is the permission/flag check that decides whether a
placeholder is emitted at all — cheap, already-cached gate calls.

**D4 — Reuse `ActionCenterService`, never recount its queues.**
It is already the permission-scoped, 60s-cached, snooze-aware aggregator over
exactly these queues (`orders`, `stock`, `money`, `people`, `compliance`,
`platform`), and it already covers paid-not-packed, packed-not-shipped, low
stock, expiring, ledger drift, PO overdue and invoice-missing. Two independent
counts of the same queue eventually disagree, and the one on the dashboard would
be the one people believe.

**D5 — Gate each panel on the permission that already gates its source data.**
No `dashboard.view` permission is introduced. A scoped admin loading `/admin`
sees placeholders only for panels they could open the underlying page for —
the same model as `ActionCenterRegistry::for($user)`. Default deny: unknown key
→ 404, missing permission → 403, feature flag off → 404 **and no placeholder
emitted server-side** (zero UI trace, per the project rule).

**D6 — 60-second per-panel cache, keyed by user only where content is
permission-scoped.**
Matches the existing sidebar-badge convention
(`Cache::remember('admin.<thing>.<count>', 60, …)` in the admin layout). The
cached payload carries its own `generated_at` so the "as of" stamp tells the
truth about staleness rather than reporting render time.

**D7 — Dashboard = now-state; Analytics = trends.** (See Non-goals.)

**D8 — Manual refresh, no polling.**
A dashboard left open all day that polls is exactly the server load the request
asks to avoid. Each panel header carries a refresh control and an "as of HH:MM"
stamp; a stale number the viewer can see is stale beats a fresh one bought with
a request every 30 seconds per open tab.

**D9 — The sales panel reads the *order* date basis, and that needs an index.**
`SalesReportService` defaults to `BASIS_SHIPPED` (`orders.shipped_at`) because
cost is stamped at pack time — right for a profit report, wrong for "what came
in today". The dashboard uses `BASIS_ORDERED` (`orders.placed_at`).
`orders` carries `idx_orders_status_shipped_at (status, shipped_at)` and
`idx_orders_status_packed_at (status, packed_at)`, but **nothing indexes
`placed_at`**, so this basis is a table scan today. One migration adds
`(status, placed_at)`. Verified against the live schema, not assumed.

---

## Compliance position

This touches money aggregates, so `compliance-officer` review is mandatory
before commit and the commit carries a `Compliance-Review:` trailer.

- **Hard rule 3 (no income projections).** Every figure is historical or
  current-state. No panel extrapolates, annualises, forecasts or shows a
  run-rate. This is a constraint on implementation, not just review: a
  "projected monthly revenue" tile would be a compliance stop.
- **Audience.** All figures are company-wide aggregates shown to staff inside
  the admin console. No distributor's earnings are shown to another distributor;
  the rule-3 carve-out about downline stats is not engaged.
- **Grievance counts are deliberately excluded from this dashboard.**
  `GrievanceComplianceReport` loads full `Ticket` rows for a month into memory
  and filters in PHP — too heavy for a panel — and its own code records that
  *an aggregate still discloses*: a viewer without `compliance.discipline` must
  never see a count that includes `ethics` / `privacy` categories. Surfacing
  grievances correctly means passing `TicketCategory::sensitiveValues()` as
  excluded categories per viewer. Out of scope here; the Action Center panel
  already surfaces `grievance.sla_due_or_breached` for those who hold
  `grievance.handle`, which is the part that needs acting on.

---

## Permission matrix

Roles: `developer` and `admin` are super staff (`Gate::before` bypass); the three
scoped roles hold only their own permissions. The whole page remains behind the
existing admin-family role middleware.

| Panel (key) | Gate | Feature flag | developer | admin | admin-operations | admin-finance | admin-compliance |
|---|---|---|---|---|---|---|---|
| Needs attention (`attention`) | `action.center.view` | `ActionCenterFeature` | ✓ | ✓ | ✓ | ✓ | ✓ |
| Sales (`sales`) | `sales.report.view` | — | ✓ | ✓ | ✓ | ✓ | ✗ |
| Order pipeline (`orders`) | — (admin family) | — | ✓ | ✓ | ✓ | ✓ | ✓ |
| Stock & warehouses (`inventory`) | `inventory.view` | `InventoryFeature` | ✓ | ✓ | ✓ | ✓ | ✗ |
| ↳ warehouse / PO / transfer tiles | `inventory.manage` | `InventoryFeature` | ✓ | ✓ | ✓ | ✗ | ✗ |
| Payouts & commission (`money`) | `finance.record` | — | ✓ | ✓ | ✗ | ✓ | ✗ |
| Engine health (`engines`) | `finance.record` | — | ✓ | ✓ | ✗ | ✓ | ✗ |
| Network (`people`) | — (admin family) | — | ✓ | ✓ | ✓ | ✓ | ✓ |

`orders` and `people` are ungated beyond the role middleware because that is the
existing gate on the pages they summarise — order *viewing* carries no extra
`can:`, and today's distributor tiles are ungated. `sales.report.view` is a
permission the seeder already grants to `admin-operations` and `admin-finance`
and which **no route currently uses**; this is its first consumer. Because no
screen is gated on it, the sales cards link to the order book rather than to the
profit report, which is gated on the separate `profit.report.view`.

The inventory panel is the one place where the panel gate and its source-page
gates differ. Stock levels, batch expiry and valuation sit behind
`inventory.view`; warehouses, purchase orders and transfers sit behind
`inventory.manage`, which `admin-finance` does not hold. Rather than split the
panel in two, the three `inventory.manage` tiles are wrapped in `@can` — added
2026-09-18 after compliance review, which caught the panel handing finance three
counts on tiles that 403 when clicked. DSH-13 covers both directions.

---

## File changes

| # | Path | New/Modified | Change |
|---|---|---|---|
| 1 | `app/Modules/Commerce/Database/Migrations/2026_09_18_120000_add_placed_at_index_to_orders.php` | New | `(status, placed_at)` index |
| 2 | `app/Modules/Admin/Support/DashboardPanels.php` | New | Panel registry — key → metadata; `visibleTo(User)` |
| 3 | `app/Modules/Admin/Services/DashboardPanelData.php` | New | One public builder per panel, each 60s-cached |
| 4 | `app/Modules/Admin/Http/Controllers/AdminDashboardPanelController.php` | New | `show(Request, string $panel)` — gate + dispatch + render fragment |
| 5 | `app/Modules/Admin/Http/Controllers/AdminDashboardController.php` | Modified | Stripped to a shell; all data code removed |
| 6 | `routes/web.php` | Modified | One route added |
| 7 | `resources/views/admin/dashboard.blade.php` | Modified | Rewritten as skeleton shell + inline loader |
| 8 | `resources/views/components/ui/panel-skeleton.blade.php` | New | Shimmer placeholder component |
| 9 | `resources/views/admin/dashboard/panels/attention.blade.php` | New | Action Center summary by group |
| 10 | `resources/views/admin/dashboard/panels/sales.blade.php` | New | Today / 7d / 30d sales |
| 11 | `resources/views/admin/dashboard/panels/orders.blade.php` | New | Order pipeline by stage |
| 12 | `resources/views/admin/dashboard/panels/inventory.blade.php` | New | Alerts, value, warehouses, POs, transfers |
| 13 | `resources/views/admin/dashboard/panels/money.blade.php` | New | Payout batches, held money, commission by bonus |
| 14 | `resources/views/admin/dashboard/panels/engines.blade.php` | New | Engine health report |
| 15 | `resources/views/admin/dashboard/panels/people.blade.php` | New | Network counts + latest joins |
| 16 | `resources/help/dashboard.md` | New | Help doc — what each panel counts |
| 17 | `app/Modules/Admin/Http/Controllers/AdminHelpController.php` | Modified | Register the help doc |
| 18 | `tests/Modules/Admin/AdminDashboardPanelTest.php` | New | Permission matrix + shell-is-empty |
| 19 | `tests/Browser/admin-dashboard.spec.js` | New | Lazy-load behaviour |
| 20 | `tests/Browser/inventory-warehouse.spec.js` | Modified | Fix the always-skipping selector |

### 1. Migration — `(status, placed_at)` on orders

One concern. Module migration directory (this project keeps migrations under
`app/Modules/*/Database/Migrations/`).

```php
public function up(): void
{
    Schema::table('orders', function (Blueprint $table): void {
        $table->index(['status', 'placed_at'], 'idx_orders_status_placed_at');
    });
}

public function down(): void
{
    Schema::table('orders', function (Blueprint $table): void {
        $table->dropIndex('idx_orders_status_placed_at');
    });
}
```

Docblock must record *why*: `SalesReportService::BASIS_ORDERED` filters
`status IN (...)` plus a range on `placed_at`; the sibling
`idx_orders_status_shipped_at` already serves the default shipped basis, and
this is its order-date counterpart.

### 2. `DashboardPanels` (registry)

`final class DashboardPanels` in `App\Modules\Admin\Support`.

```php
/**
 * @var array<string, array{title: string, permission: ?string, feature: ?class-string,
 *                          lazy: bool, span: string}>
 */
public const PANELS = [
    'attention' => ['title' => 'Needs attention', 'permission' => 'action.center.view', 'feature' => ActionCenterFeature::class, 'lazy' => false, 'span' => 'full'],
    'sales'     => ['title' => 'Sales',           'permission' => 'sales.report.view',  'feature' => null,                     'lazy' => false, 'span' => 'full'],
    'orders'    => ['title' => 'Order pipeline',  'permission' => null,                 'feature' => null,                     'lazy' => true,  'span' => 'half'],
    'inventory' => ['title' => 'Stock & warehouses', 'permission' => 'inventory.view',  'feature' => InventoryFeature::class,   'lazy' => true,  'span' => 'half'],
    'money'     => ['title' => 'Payouts & commission', 'permission' => 'finance.record', 'feature' => null,                    'lazy' => true,  'span' => 'half'],
    'engines'   => ['title' => 'Engine health',   'permission' => 'finance.record',     'feature' => null,                     'lazy' => true,  'span' => 'half'],
    'people'    => ['title' => 'Network',         'permission' => null,                 'feature' => null,                     'lazy' => true,  'span' => 'full'],
];
```

`lazy => false` means fetch on `DOMContentLoaded` (above the fold);
`lazy => true` means fetch on intersection. Order of the const is render order.

```php
/**
 * The panels this viewer may see, in render order. Permission and flag are
 * both checked HERE so a hidden panel never reaches the DOM at all — a
 * client-side hide would leak the existence of a flag-off module.
 *
 * @return array<string, array{...}>
 */
public static function visibleTo(?User $user): array
```

Implementation: iterate `PANELS`; skip when `permission !== null && ! $user?->can($permission)`;
skip when `feature !== null && ! Feature::for(null)->active($feature)`.
Use `Laravel\Pennant\Feature::for(null)->active(...)` — the site-wide form used
everywhere in this codebase.

```php
public static function exists(string $key): bool
public static function title(string $key): string
```

### 3. `DashboardPanelData` (service)

`final class DashboardPanelData` in `App\Modules\Admin\Services`, constructor-promoted
dependencies, one public method per panel. Every method wraps its body in

```php
Cache::remember('admin.dashboard.'.$key.'.'.$user->id, 60, fn () => [...] + ['generated_at' => now()]);
```

Constructor dependencies (all existing, none new):

```php
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
```

**`attention(User $user): array`**
Returns `ActionCenterService::summary($user)` verbatim plus `generated_at`. Do
**not** re-cache it — `summary()` is already 60s-cached per user; wrapping it
again would double the staleness window. Shape per row:
`{key, group, label, description, count, severity, statutory, sla_hours, oldest_at, oldest_age_hours}`,
grouped by `ActionGroup` value (`orders`, `stock`, `money`, `people`,
`compliance`, `platform`). A group with nothing outstanding has zero rows —
render nothing for it (silence means nothing to do; this is existing behaviour).

**`sales(User $user): array`**
Three windows, each `SalesReportService::totals(SalesScope::all(), $from, $to, SalesReportService::BASIS_ORDERED)`:
`today` (`now()->startOfDay()` → `now()`), `week` (`now()->subDays(6)->startOfDay()` → `now()`),
`month` (`now()->startOfMonth()` → `now()`). For each, also
`refundTotals(SalesScope::all(), $from, $to)`.

Derived per window — **net revenue is `gross_ex_gst_paise`**, which
`SalesReportService` already computes ex-GST; do not subtract `gst_paise` from it
again, and do not present `cash_paise` as revenue (it includes GST, shipping and
collection fee). Present: `orders`, `gross_ex_gst_paise`, `gst_paise`,
`cash_paise` (labelled "collected"), and refunds as a separate negative line —
never netted silently into the first figures.

BV for the same windows: one query per window against `bv_ledger_entries`
(`SUM(bv_paise)` where `type = 'accrual'` and `effective_at` in range; the table
is indexed on `effective_at`).

**`orders(User $user): array`**
`orders` grouped by `status` → `array<string, int>`, one query
(`DB::table('orders')->groupBy('status')->selectRaw('status, COUNT(*) c')`).
Render as a pipeline in lifecycle order using `Order` model constants, never
literal strings: `placed`, `paid`, `ready_to_ship`, `shipped`,
`awaiting_collection`, `delivered`, `confirmed`, then a separate exceptions row
for `cancelled`, `refund_requested`, `refund_inspection`, `refund_approved`,
`refunded`. A status with a zero count still renders (a pipeline with a gap is
information); the exceptions row hides when all are zero.

**`inventory(User $user): array`**
`low_stock` / `expiring` / `expired` counts from `InventoryAlertService`
(`->count()` on each returned collection, as today), `expiry_days` from
`InventorySettings::expiryAlertDays()`, `stock_value_paise` from
`StockValuationService::currentValuePaise()` (**the cheap one — never
`valueAtPaise()`, which replays the whole `stock_movements` ledger with no index
serving that scan**), plus: active warehouse count (`warehouses` where
`status = 'active'`), open POs (`purchase_orders` where `status IN ('sent','partially_received')`),
transfers in transit (`stock_transfers` where `status = 'dispatched'`).

**`money(User $user): array`**
- Latest batch: `PayoutBatch::query()->latest('batch_date')->first()` → type,
  `batch_date`, `status`, `total_net_paise`, `distributor_count`.
- Batches awaiting approval: count where `status = PayoutBatch::STATUS_PENDING`.
- Held money: `payout_line_items` grouped by status restricted to
  `PayoutLineItem::HELD_STATUSES`, summing `gross_paise` — money sitting in
  wallets that nothing has debited.
- Commission this month: `WalletService::commissionTotalsByType(now()->startOfMonth(), now())`
  → company-wide, already filtered to `WalletService::BONUS_CREDIT_TYPES`.
  **Must not include `WalletService::REPURCHASE_TYPES`** — `commissionTotalsByType`
  already excludes them; do not add them back. Label rows via
  `WalletLedgerEntry::typeLabels()`.
- A stuck-batch flag: for a batch in `processing`, compare
  `PayoutService::batchLastSignOfLife($batch)` against
  `PayoutService::STUCK_BATCH_MIN_IDLE_SECONDS`. **`payout_batches.updated_at`
  is not a liveness signal** — the row is written twice per sweep, so it records
  when the sweep started; the line-item writes are the heartbeat. Use the
  service method, do not re-derive.

**`engines(User $user): array`**
`EngineHealthService::report(Carbon::now())` → `EngineHealthReport`
(`failures`, `missing`, `stuck`, `prematureFreezes`, `chainAlerts`,
`isHealthy()`, `total()`). Return the report object plus `generated_at`. This is
the same call `Platform\EngineRunsFailedProvider::count()` makes, so this panel
and the Action Center platform row can never disagree.

**`people(User $user): array`**
Replaces today's tiles. All `distributors` JOIN `users` (never bare `users` —
that is the "11 pending vs 1 in the queue" bug the controller comment records):
`active`, `pending`, `frozen`, `cooling_off_active`, `cooling_off_expiring`
(≤ 7 days), `joined_this_month` (`distributors.effective_date >= now()->startOfMonth()`),
plus `latest` — the 8 most recent rows (id, adn, full_name/email, status,
effective_date), i.e. the existing Recent Registrations query.
**Dropped:** `total_users`, `audit_entries_today`.

### 4. `AdminDashboardPanelController`

```php
final class AdminDashboardPanelController extends Controller
{
    public function show(Request $request, string $panel, DashboardPanelData $data): View
}
```

Order of checks, all default-deny:

1. `abort_unless(DashboardPanels::exists($panel), 404);`
2. `$meta = DashboardPanels::PANELS[$panel];`
3. `abort_if($meta['feature'] !== null && ! Feature::for(null)->active($meta['feature']), 404);`
   — 404 not 403: a flag-off module must not confirm it exists.
4. `abort_unless($meta['permission'] === null || $request->user()->can($meta['permission']), 403);`
5. Dispatch via `match ($panel) { 'attention' => $data->attention($user), ... }` —
   an explicit `match`, not a variable method call.
6. `return view('admin.dashboard.panels.'.$panel, [...$payload, 'panelKey' => $panel, 'title' => $meta['title']]);`

The returned view extends nothing — it is a bare fragment.

### 5. `AdminDashboardController` (strip)

Becomes:

```php
public function index(Request $request): View
{
    return view('admin.dashboard', [
        'panels' => DashboardPanels::visibleTo($request->user()),
    ]);
}
```

Remove the `AuditLogPresenter`, `InventoryAlertService`, `InventorySettings`,
`ActionCenterService` and `DB` imports and constructor/method params. The
presenter keeps two other callers (`AdminAuditLogController`,
`AdminDistributorController`) so nothing is orphaned by this.

### 6. `routes/web.php`

Immediately after the existing dashboard line (269):

```php
Route::get('/dashboard/panel/{panel}', [AdminDashboardPanelController::class, 'show'])
    ->whereIn('panel', array_keys(DashboardPanels::PANELS))
    ->name('dashboard.panel');
```

`whereIn` makes an unknown key a route-level 404 before the controller runs.
Add the two `use` imports at the top of the file.

### 7. `resources/views/admin/dashboard.blade.php` (rewrite)

Body is one loop:

```blade
<div class="space-y-5" id="dashboardPanels">
    @foreach($panels as $key => $meta)
    <div data-panel-url="{{ route('admin.dashboard.panel', $key) }}"
         data-panel-lazy="{{ $meta['lazy'] ? '1' : '0' }}"
         class="{{ $meta['span'] === 'half' ? 'lg:w-1/2' : '' }}">
        <x-ui.panel-skeleton :title="$meta['title']" />
    </div>
    @endforeach
</div>
```

Half-span panels pair up — wrap the two `half` runs in a
`grid grid-cols-1 lg:grid-cols-2 gap-5` container rather than using widths;
the implementer should group consecutive `half` panels into one grid div.

Then `@push('scripts')` with this loader **verbatim**:

```js
<script>
(function () {
    var root = document.getElementById('dashboardPanels');
    if (!root) { return; }

    function markup(state, url) {
        if (state === 'error') {
            return '<div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">' +
                '<p class="text-sm text-gray-600">This panel could not be loaded.</p>' +
                '<button type="button" data-panel-retry class="mt-2 text-sm font-medium text-brand-700 hover:text-brand-800">Try again</button>' +
                '</div>';
        }
        return '';
    }

    function load(el) {
        if (el.dataset.panelState === 'loading') { return; }
        el.dataset.panelState = 'loading';
        fetch(el.dataset.panelUrl, {
            headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (r) {
                if (!r.ok) { throw new Error(String(r.status)); }
                return r.text();
            })
            .then(function (html) {
                el.innerHTML = html;
                el.dataset.panelState = 'done';
            })
            .catch(function () {
                el.dataset.panelState = 'error';
                el.innerHTML = markup('error', el.dataset.panelUrl);
            });
    }

    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-panel-url]'));

    panels.filter(function (el) { return el.dataset.panelLazy !== '1'; }).forEach(load);

    var lazy = panels.filter(function (el) { return el.dataset.panelLazy === '1'; });

    if (!('IntersectionObserver' in window)) {
        lazy.forEach(load);
    } else {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    io.unobserve(entry.target);
                    load(entry.target);
                }
            });
        }, { rootMargin: '250px' });
        lazy.forEach(function (el) { io.observe(el); });
    }

    root.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-panel-retry], [data-panel-refresh]');
        if (!trigger) { return; }
        var el = trigger.closest('[data-panel-url]');
        if (!el) { return; }
        el.dataset.panelState = '';
        load(el);
    });
})();
</script>
```

Note the loader guards only on `loading` (not `done`) so refresh works.

### 8. `x-ui.panel-skeleton`

Props: `title`. Renders the card chrome with the real title and 3 shimmer bars
(`animate-pulse bg-gray-100`), so the page has correct structure and labels
before any data arrives and does not reflow when content lands. Light-first
utilities only — **no `dark:` variants**; `html.dark` remapping in `app.css` does
the dark pass and a `dark:` variant fights that layer.

### 9–15. Panel fragment views

Each is a bare fragment (no `@extends`) whose root is `<x-ui.card>` carrying the
panel title and, in the `actions` slot, the "as of {{ $generated_at->format('H:i') }}"
stamp plus `<button type="button" data-panel-refresh>` with a `lucide-refresh-cw`
icon. Conventions that apply to all seven:

- Every displayed number through `IndianNumber::format()`; every money figure
  through `IndianNumber::rupees($paise)`. Never `Number::format`.
- Icons via `blade-lucide-icons` (`<x-lucide-*>` or `svg('lucide-…')`). No raw SVG.
- `{{ }}` only, never `{!! !!}`.
- No `dark:` variants (see above).
- Reuse `x-ui.stat`, `x-ui.badge`, `x-ui.button`, `x-ui.empty-state`.
- A bare `{{ }}` or `@directive` inside a component *tag* breaks compilation and
  `view:cache` does not catch it — `php -l` the compiled views (see acceptance).
- Every count links somewhere real: `attention` rows →
  `admin.action-center.show` with the row key; `sales` → `admin.reports.profit.index`;
  `orders` → `admin.commerce.orders.index` with the status filter;
  `inventory` → `admin.inventory.reports.low-stock` / `.batch-expiry` /
  `.valuation` / `admin.inventory.warehouses.index`; `money` →
  `admin.compensation.weekly-payouts.index`; `engines` →
  `admin.compensation.engine-runs.index`; `people` →
  `admin.distributors.index` with the matching status filter.

### 16–17. Help doc

`resources/help/dashboard.md` — what each panel counts, which permission reveals
it, the 60-second cache, why the "as of" stamp exists, and explicitly **why
grievance figures are not here**. Register in `AdminHelpController::DOCS` as the
first entry:

```php
'dashboard' => [
    'title' => 'Admin Dashboard',
    'description' => 'What each dashboard panel counts, which permission reveals it, why panels load one at a time, and how fresh the numbers are.',
    'file' => 'dashboard.md',
],
```

---

## Slices

| Slice | Title | Files | Depends on | Model |
|---|---|---|---|---|
| 1 | Foundation — index, registry, data service, controller, route, shell strip | 1, 2, 3, 4, 5, 6 | — | Opus |
| 2 | State panel views — orders, inventory, engines, people | 11, 12, 14, 15 | 1 | Sonnet |
| 3 | Money panel views — attention, sales, money | 9, 10, 13 | 1 | Sonnet |
| 4 | Shell + skeleton + lazy loader | 7, 8 | 1 | Sonnet |
| 5 | Tests + help doc | 16, 17, 18, 19, 20 | 2, 3, 4 | Opus |

Slices 2, 3 and 4 own disjoint files and run in parallel after slice 1.

---

## Test plan

**Pest — `tests/Modules/Admin/AdminDashboardPanelTest.php`** (permission matrix
is the source of truth; follow the `arsUser($role)` pattern from
`tests/Modules/Admin/AdminRoleSeparationTest.php`, seeding
`RolesAndPermissionsSeeder` and forgetting the permission cache in `beforeEach`).

| ID | Scenario | Expect |
|---|---|---|
| DSH-01 | `admin` GETs each of the 7 panels | 200 |
| DSH-02 | `admin-compliance` GETs `sales`, `inventory`, `money`, `engines` | 403 each |
| DSH-03 | `admin-operations` GETs `money`, `engines` | 403 each |
| DSH-04 | `admin-operations` GETs `sales`, `inventory`, `orders`, `people`, `attention` | 200 each |
| DSH-05 | `admin-finance` GETs `money`, `engines`, `sales`, `inventory` | 200 each |
| DSH-06 | any admin GETs `/admin/dashboard/panel/nope` | 404 |
| DSH-07 | `InventoryFeature` off → `inventory` panel | 404, and `visibleTo()` omits the key |
| DSH-08 | a distributor (non-staff) GETs any panel | redirect / 403 from role middleware |
| DSH-09 | `/admin` response body contains no panel *data* — assert it does **not** contain an ADN, a rupee figure, or the string `Recent Audit Events` | passes |
| DSH-10 | `visibleTo()` for `admin-compliance` returns exactly `attention`, `orders`, `people` | passes |
| DSH-11 | `people` payload counts join `distributors`, so a bare `users` row with `status = 'pending'` and no distributor does not inflate `pending` | passes |
| DSH-12 | `sales` totals use `BASIS_ORDERED`: an order `placed_at` today but never shipped is counted today | passes |

**Playwright — `tests/Browser/admin-dashboard.spec.js`** (runs against the dev
app on `:8084` with the dev database, `workers: 1`, so specs must be read-only
and tolerant of data):

| ID | Scenario | Expect |
|---|---|---|
| E2E-01 | `/admin` loads | every visible panel title present as a heading before any data |
| E2E-02 | after load | above-fold panels (`attention`, `sales`) have `data-panel-state="done"` |
| E2E-03 | on load, without scrolling | at least one `lazy` panel is still not `done` |
| E2E-04 | scroll to bottom | every panel reaches `done` |
| E2E-05 | page text | does not contain `Recent Audit Events` |
| E2E-06 | click a panel's refresh control | panel returns to `done` and content is present |
| E2E-07 | network | exactly one request per panel, no repeats after 5s idle (proves no polling) |

**Fix — `tests/Browser/inventory-warehouse.spec.js:439-460`.** Both tests match
`hasText: 'Inventory —'`, a string the current view does not render (it says
"Inventory alerts"), so both have been silently `test.skip`-ing rather than
testing. Repoint at the new inventory panel and drop the skip branches — a test
that always skips is not a test.

Playwright cannot cover the scoped-role directions: `fixtures.js` exposes only
`adminPage` and `distributorPage`, with no storage state per scoped role.
The matrix is therefore proven in Pest (DSH-02…DSH-05, where roles can be
created), and Playwright covers structure and lazy-load behaviour. Adding scoped
role fixtures is a worthwhile follow-up, not scope here.

**Verification caveat.** The dev database's newest order is `2026-09-01`, so
`sales.today` renders zero and cannot be visually confirmed. Confirm the today
window against `sales.month` (which has data) and rely on DSH-12 for the
date-basis logic.

---

## Acceptance criteria

- [ ] `/admin` issues no data query. Verify with Laravel Debugbar or a
      `DB::listen` assertion in DSH-09 — the shell request logs zero queries
      beyond session/auth.
- [ ] The Recent Audit Events card, the Audit Events Today tile and the Total
      Users tile are gone from `resources/views/admin/dashboard.blade.php`.
- [ ] Every panel is reachable, gated exactly as the matrix says, and a flag-off
      panel emits no placeholder.
- [ ] `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact tests/Modules/Admin/AdminDashboardPanelTest.php` — green.
- [ ] Full suite green (same `-e` form, no bare `php artisan test`).
- [ ] `./vendor/bin/pint --dirty` clean.
- [ ] `./vendor/bin/phpstan analyse` — Larastan level 7, no new baseline entries
      under `app/`.
- [ ] `php artisan migrate` forward-only on dev; confirm
      `SHOW INDEX FROM orders` lists `idx_orders_status_placed_at`.
- [ ] `npx playwright test tests/Browser/admin-dashboard.spec.js tests/Browser/inventory-warehouse.spec.js` — green, and the two inventory tests actually run rather than skip.
- [ ] Compiled views lint: `php -l` over `storage/framework/views/*.php` after
      `php artisan view:cache`.
- [ ] `compliance-officer` review passed; commit carries `Compliance-Review:`.
- [ ] `resources/help/dashboard.md` written and registered.

---

## Open questions for the approver

1. **Panel set.** Seven panels as listed — is anything missing that you look at
   daily, or anything here you would not open?
2. **Removals.** Total Users, Audit Events Today and the standalone Recent
   Registrations card all go (§"Current behaviour"). Recent Registrations
   survives as a list inside Network. Confirm.
3. **Grievances excluded** for the disclosure and cost reasons in
   §Compliance. The Action Center panel still surfaces breached grievance SLAs
   to those holding `grievance.handle`. Confirm that is enough.

---

## Payload contract — as built in Slice 1

Slice 1 is complete and this section, not the sketch above, is what the view
slices must code against. Two things moved during implementation:

- The panel builders take **no `User` argument** except `attention(User $user)`.
  The rest are `sales()`, `orders()`, `inventory()`, `money()`, `engines()`,
  `people()` — the viewer does not vary their content, so the 60s cache key
  carries no user id.
- The controller passes **`$panelKey`** and **`$panelTitle`** (not `$title`)
  alongside the spread payload.

Every view is a bare fragment — **no `@extends`, no `@section`**. Root element:

```blade
<x-ui.card flush title="{{ $panelTitle }}">
    <x-slot:actions>
        <span class="text-[11px] tabular-nums text-gray-500">as of {{ $generated_at->format('H:i') }}</span>
        <button type="button" data-panel-refresh
                class="inline-flex items-center rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
                aria-label="Refresh {{ $panelTitle }}">
            {{ svg('lucide-refresh-cw', 'w-3.5 h-3.5') }}
        </button>
    </x-slot:actions>
    ...
</x-ui.card>
```

`$generated_at` is an `Illuminate\Support\Carbon` on every panel.

### `attention` — `resources/views/admin/dashboard/panels/attention.blade.php`

```
groups        Collection<string, array<int, array{key, group, label, description,
              count, severity, statutory, sla_hours, oldest_at, oldest_age_hours}>>
generated_at  Carbon
```

Keys of `groups` are `ActionGroup` constants; label them with
`\App\Modules\ActionCenter\Support\ActionGroup::label($key)`. A group with zero
rows renders **nothing at all** — no header, no empty state. If every group is
empty, render a single `x-ui.empty-state` saying nothing needs attention.
Each row links to `route('admin.action-center.show', $row['key'])`.
`severity` drives the badge tone; `statutory === true` earns a visible marker.
Sort rows within a group by `count` descending.

### `sales` — `sales.blade.php`

```
windows       array<string, array{label: string, from: Carbon,
                  totals: array{orders, gross_ex_gst_paise, gst_paise, discount_paise,
                                points_paise, shipping_paise, collection_fee_paise, cash_paise},
                  refunds: same shape,
                  bv_paise: int}>
generated_at  Carbon
```

Keys in order: `today`, `week`, `month`. Render one column per window:
orders (`IndianNumber::format`), revenue = `totals.gross_ex_gst_paise`
(`IndianNumber::rupees`), collected = `totals.cash_paise`, BV = `bv_paise`
(divide by 100 for display — it is a paise-scaled BV figure, use the `@bv`
convention), and a refunds line showing `refunds.orders` and
`refunds.cash_paise` **only when `refunds.orders > 0`**.
Never subtract refunds from the figures above them. Never subtract
`gst_paise` from `gross_ex_gst_paise` — it is already net.
Link the card to `route('admin.reports.profit.index')`.

### `orders` — `orders.blade.php`

```
pipeline          array<string, int>   # 7 lifecycle statuses, zeros included
exceptions        array<string, int>   # only non-zero entries present
pipeline_total    int
exceptions_total  int
generated_at      Carbon
```

Render `pipeline` as an ordered row of stages, each linking to
`route('admin.commerce.orders.index', ['status' => $status])`. Label statuses
with `\App\Modules\Commerce\Models\Order::STATUS_LABELS` if that constant
exists; otherwise `Str::headline($status)`. Show `exceptions` as a separate
muted row, and omit that row entirely when `exceptions` is empty.

### `inventory` — `inventory.blade.php`

```
low_stock, expiring, expired, expiry_days, warehouses,
open_purchase_orders, transfers_in_transit   int
stock_value_paise                            int
generated_at                                 Carbon
```

Links: low_stock → `admin.inventory.reports.low-stock`; expiring/expired →
`admin.inventory.reports.batch-expiry`; stock_value_paise →
`admin.inventory.reports.valuation`; warehouses →
`admin.inventory.warehouses.index`; open_purchase_orders →
`admin.inventory.purchase-orders.index`; transfers_in_transit →
`admin.inventory.transfers.index`. The expiring label reads
"Expiring ≤{{ $expiry_days }}d".

### `money` — `money.blade.php`

```
latest_batch        ?PayoutBatch   # batch_type, batch_date, status,
                                   # total_net_paise, distributor_count
stuck_since         ?Carbon        # non-null only when the latest batch is
                                   # processing and has been idle past the threshold
awaiting_approval   int
held                array<string, int>   # PayoutLineItem status => SUM(gross_paise)
held_total_paise    int
commission          array<string, int>   # wallet type => SUM(amount_paise) this month
commission_labels   array<string, string>
generated_at        Carbon
```

`latest_batch` may be null (no batch ever run) — render an empty state, not a
crash. `stuck_since` non-null is a **danger** badge reading "no activity since
{{ $stuck_since->diffForHumans() }}". Label held rows and commission rows from
`commission_labels` / `PayoutLineItem` status labels, never raw enum strings.
Link to `route('admin.compensation.weekly-payouts.index')`.

### `engines` — `engines.blade.php`

```
report        \App\Modules\Compensation\Services\DTOs\EngineHealthReport
              # ->failures ->missing ->stuck ->prematureFreezes ->chainAlerts
              # ->isHealthy(): bool  ->total(): int
generated_at  Carbon
```

When `$report->isHealthy()`, render a single green "all engines healthy" line —
nothing else. Otherwise one counted row per non-empty bucket, linking to
`route('admin.compensation.engine-runs.index')`. Do not enumerate individual
items; this is a health indicator, and the Engine Runs page is the detail view.

### `people` — `people.blade.php`

```
active, pending, frozen, joined_this_month,
cooling_off_active, cooling_off_expiring   int
latest   Collection of stdClass{id, adn, effective_date, email, full_name, status}
generated_at Carbon
```

Counts link to `route('admin.distributors.index', ['status' => ...])`;
cooling-off ones to `['cooling_off' => 'active'|'expiring']`.
`latest` renders as the compact registrations list the old dashboard had:
ADN linking to `route('admin.distributors.show', $row->id)`, name or email
beneath, an `x-ui.badge` from
`\App\Modules\Identity\Models\User::STATUS_LABELS[$row->status]`, and the
`effective_date` formatted `d M Y, h:i A`. Empty state when `latest` is empty.

---

## Outcome — 2026-09-18

Shipped. `/admin` is a shell that issues no query of its own and seven panels
that fetch their own rendered HTML fragment. The GRN create page was fixed in
the same change at the client's request (see the second section below).

### What landed

All twenty file rows in the table above landed, plus four files the plan did not
anticipate:

| Path | Why it was added |
|---|---|
| `app/Modules/Commerce/Database/Migrations/2026_09_18_120000_add_placed_at_index_to_orders.php` | The sales panel counts on `orders.placed_at`, and `orders` had covering indexes for `shipped_at` and `packed_at` but none for `placed_at`. |
| `resources/help/dashboard.md` | Required by the standing rule that a feature change updates its help doc in the same change. Registered as the first entry in `AdminHelpController::DOCS`. |
| `tests/Feature/Inventory/GrnPurchaseOrderLinesTest.php` | The GRN work, which was not in the original plan. |
| `docs/compliance/risk-register.md` (R-100) | See "Compliance review" below. |

### Deviations from the spec

1. **Builders take no `User`.** Only `attention(User $user)` does. Every other
   payload is company-wide and the permission is checked in the controller
   before the builder is called, so passing a viewer in would have implied a
   scoping that does not exist — and would have forced a per-user cache key for
   content that is identical for everyone entitled to see it.
2. **The controller passes `$panelKey` / `$panelTitle`, not `$title`.** `$title`
   collides with the `<x-ui.card title>` attribute in a fragment that is itself
   a card.
3. **The attention panel does not sort.** The spec had it ordering by count.
   `ActionCenterService::summary()` already orders criticals first within each
   group, and re-sorting by count put a breached statutory clock with one item
   outstanding below a non-statutory warning with fifty. The panel now renders
   the service's order unchanged, which is also the order the Action Center
   screen uses.
4. **Three inventory tiles are gated a second time.** The panel is gated on
   `inventory.view`; warehouses, purchase orders and transfers in transit live
   behind `inventory.manage`, which `admin-finance` does not hold. Those three
   tiles render under `@can('inventory.manage')` so the panel is never wider
   than the screens it links to. The permission matrix and the help doc say so.
5. **The sales cards link to the order book, not the profit report.** The panel
   is gated on `sales.report.view` (revenue) and the profit screens on
   `profit.report.view` (supplier cost and margin). The two are separated
   deliberately, so a card on the revenue panel must not lead somewhere its own
   viewer may be refused.
6. **DSH-09 asserts a baseline, not a query budget.** The shell costs 25
   queries and not one of them is the dashboard's: they are the Spatie
   permission tables, Pennant resolving each killswitch once, and the sidebar's
   own badge counts — the chrome that wraps every admin page. The test now
   measures a plain admin page carrying the same chrome and asserts the
   dashboard costs no more, which fails if a tile is ever computed eagerly again
   and does not need rewriting when the nav changes.

### Compliance review

`compliance-officer`, 2026-09-18: **PASS with recommendations.** No Critical, no
High. Hard rules 2, 3, 5 and 8 verified clear; no per-distributor money on any
panel; flag-off panels emit nothing and 404 rather than 403.

Five Mediums and six Lows were raised. All were addressed in this change except
one, which is now **R-100**: the Action Center's grievance SLA providers apply no
sensitive-category filter, so their count includes ethics and privacy tickets for
holders of `grievance.handle` who cannot open them. That is Action Center
behaviour since 2026-09-12, not something this change introduced — the change
reduced the landing-page disclosure from the old card's ticket numbers to a bare
count, and surfaced the gap by writing the control down in the help doc as though
it already existed. The doc now describes what is true and points at R-100. It is
not fixed here because filtering the count would hide breached **statutory**
clocks from the operations staff who action them; who watches those clocks is a
product decision, not a cleanup.

Also fixed from that review: the two `pluck(DB::raw('SUM(...)'))` aggregates now
alias their columns (that form renders held money as ₹0 instead of throwing if
the driver ever names the result column differently); the money panel's
"Commission this month" heading is now "Bonus credits this month", because the
constant behind it includes manual adjustments and awards; BV goes through
`Bv::points()` rather than a float division; the cooling-off tiles carry
`status=active` so the tile and the list it opens apply the same rule to a
statutory figure; and the recent-registrations cache no longer holds email
addresses.

### Deferred

- **No grievance panel.** The monthly report loads whole tickets for a month
  into memory, and a landing page is the wrong place for complaint volumes.
  Revisit if a cheap aggregate is built.
- **Order-fulfilment states are not pre-built.** `docs/plans/2026-09-18-order-fulfilment.md`
  is unbuilt and is itself a compliance FAIL with five High findings. The
  pipeline panel reads the statuses that exist today.
- **No auto-refresh.** Manual refresh with a 60-second cache, per the client's
  decision. The Playwright spec asserts no polling: five seconds idle, request
  count unchanged.
- **The admin chrome costs 25 queries on a cold cache** — 4 Spatie permission
  reads, 11 sidebar badge counts, and 10 Pennant resolutions (5 flags, each a
  select plus a first-resolution insert; 5 selects once the rows exist). The
  badge counts are `Cache::remember`-ed for 60s in the layout's `@php` block, so
  in any environment with a real cache store this is once a minute rather than
  once a request — which is why it shows up in full in a test, where the cache
  is the array driver. Pre-existing, applies to every admin screen, out of scope
  here — but it is now measured, and DSH-09's baseline moves with it.

### GRN create page — same change

Not in the original plan; requested while this was in flight.

- **Repaired corrupted markup.** `_form.blade.php` carried
  `</x-ui.card> class="flex items-center gap-3">` — a `<div>` whose opening tag
  had been overwritten, leaving its attributes loose in the output and a submit
  button collapsed into a vertical strip down the side of the page. Introduced
  by `8395c6ee` ("make the cost of received goods the landed cost"). Purely
  visual, because `x-ui.button` defaults `type` to `submit`. Nothing rendered
  this page in a test, which is how it shipped; GPOL-06 now does.
- **Selecting a purchase order fills the line table.** New endpoint
  `admin.inventory.grns.po-lines`, inside the `can:inventory.manage` group,
  404ing any order that is not sent or partially received. It offers what is
  still **owed**, not what was ordered — prefilling the original quantity would
  have the receiver book units that never arrived. The `?purchase_order_id=`
  deep link and the dropdown now share `linesFromPurchaseOrder()`.
- Two layout fixes: a `min-w-[220px]` on the product column (the select was
  collapsing to "Cho…") and `items-end` on the landed-cost grid (the
  "Loading / handling (₹)" label wrapped and dropped its input a row below its
  siblings).

### Also fixed on the way through

`tests/Feature/ActionCenter/BadgeCountTest.php` held two assertions that could
not fail. Both anchored on an `admin/action-center` link they believed was in
the sidebar; it actually resolved in the dashboard **body**, because the
sidebar's Action Center item has been `developer`-only since 2026-09-12, and nav
badges render only in the sidebar, which precedes the body. One test's premise
was false as well: `admin-finance` carries eleven criticals on an empty database
(`platform.engine_runs_failed` — no engine runs means every scheduled period
reads as missing), so "a viewer with nothing outstanding" never existed. Moving
the dashboard to lazy panels removed the body link and exposed both. The tests
now assert the permission scoping they always claimed to, against
`ActionCenterService::summary()` directly.

## Browser verification — 2026-09-18

Run at the client's request against dev (`http://localhost:8084`), with
`tests/Browser/admin-dashboard.spec.js` and `tests/Browser/inventory-warehouse.spec.js`.
It found three defects that the 3095-test PHP suite could not, and two of them
were mine.

### 1. No panel survived the cache (the serious one)

Every cached panel rendered as a 500 on a real page load. `config/cache.php`
sets `'serializable_classes' => false` — a deliberate gadget-chain defence — so
every store unserializes with `allowed_classes: false` and **every object in a
cached payload returns as `__PHP_Incomplete_Class`**. All six cached payloads
carried at least a `Carbon` (`generated_at`); `money()` also cached a
`PayoutBatch`, `engines()` an `EngineHealthReport`, and `people()` a
`Collection` of `stdClass`.

There is no error on write and no null on read: the panel dies at the first
method call against the husk. **The suite cannot see this** — the test cache
store is `array`, which never serialises — which is exactly why it shipped past
a green suite.

Fixed by `DashboardPanelData::remember()`: the cache now carries arrays and
scalars only, `generated_at` goes in as a Unix timestamp and is rebuilt on the
way out, the payout batch is cached as its five rendered columns, and the
engine report is cached as its five lists and reconstructed. `money.blade.php`
and `people.blade.php` read arrays instead of objects. **DSH-15** round-trips
each cache entry through `unserialize(serialize($raw), ['allowed_classes' => false])`
and fails if anything in it is an object; **DSH-16** asserts the rebuild still
hands the views a real `Carbon` and a real `EngineHealthReport`.

### 2. Two panel titles rendered as `Stock &amp; warehouses`

`<x-ui.card title="{{ $panelTitle }}">` escapes into the attribute and the
component escapes again on echo. Fixed to `:title="$panelTitle"` in all seven
panels and in `x-ui.panel-skeleton`. **DSH-17** covers both surfaces.

### 3. Tile labels truncated to nonsense

"Active warehous…", "Ready T…", "Transfers in tran…" — three tiles to a
half-width card leaves no width to truncate against. `x-ui.stat` gained an
opt-in `label-lines="2"` (default unchanged, so no other screen moves) with a
reserved two-line height so the numbers below stay aligned; the fourteen
dashboard tiles opt in. Also widened the GRN `GST %` column from `w-24` to
`w-28`, where `18.00` plus the number spinner was clipped.

### Test-side corrections

- `admin-dashboard.spec.js` hard-coded `attention` as an above-fold panel.
  `ActionCenterFeature` and `InventoryFeature` are **off on dev**, and a
  flag-off panel leaves no trace at all, so two specs were asserting a flag
  rather than the loading contract. The above-fold set now comes from the DOM
  (`data-panel-lazy="0"`), and the inventory-panel specs skip rather than fail
  when the module is flagged off.
- E2E-07 counted panel fetches from **two page visits**: the `adminPage`
  fixture finishes its login on `/admin`, and `goto` resolves at the load event
  while the lazy panels are started by an IntersectionObserver callback that
  runs after it. It now leaves the page before counting.

### Result

With both flags temporarily on (and restored to `false` afterwards — rows 41
and 47, `features` table unchanged at 24 rows), all seven panels render and
load: **admin-dashboard.spec.js 7/7**, **inventory-warehouse.spec.js 28 passed
/ 2 skipped**. The GRN page was confirmed visually: no corrupted button strip,
the product column readable, the landed-cost labels on one line, and selecting
PO-2026-000028 fills supplier, warehouse and the line table with the quantity
still owed.

## R-100 closed — 2026-09-18

The client chose to filter the count and give compliance its own row.
`GrievanceSlaDueOrBreachedProvider` and `GrievanceThirdPartyOverdueProvider`
now exclude `TicketCategory::sensitiveValues()`;
`GrievanceSensitiveSlaDueOrBreachedProvider` and
`GrievanceSensitiveThirdPartyOverdueProvider` extend them with the
complementary scope and `compliance.discipline` as their `permission()`, so
`ActionCenterRegistry::for()`'s ordinary filter is the whole access rule — no
viewer checks inside a provider, which matters because providers are
singletons.

The halves are disjoint and exhaustive: every ticket past a clock is counted
once, on exactly one row, so no breached **statutory** clock is hidden from
everyone — it moves to the desk cleared to open the ticket. The sensitive rows
inherit the clock rather than restating it, so the pairs cannot drift; that is
why the two parents are no longer `final`. `resources/help/dashboard.md`,
`docs/runbooks/action-center.md` and the risk register are updated.

## Shipped and deployed to staging — 2026-09-18

Four commits on `main` (`2bb680ea` dashboard, `f5623674` GRN, `a8353556`
INV-04, `963acdbd` R-100), pushed, then deployed to `arovolife-staging`
(app 6390605) by Cloudways `git_pull` — which does the pull and nothing else.
The rest, over SSH as `master_mvgumpkwtu`:

- `route:clear`, `config:clear`, `view:clear`, `event:clear`. Two new routes
  ship in this change and `bootstrap/cache/routes-*.php` was baked earlier, so
  without the first of these both endpoints 404.
  **Not `cache:clear` or `optimize:clear`:** `RedisStore::flush()` is
  `flushdb()`, and this Redis is shared unauthenticated with eight other apps
  on the box, two of them production.
- `migrate --force` — one pending migration, the additive
  `idx_orders_status_placed_at`. 13 orders on staging; 66 ms.
- `npm run build` under nvm's Node v24.20.0 (`/usr/bin/node` is 20.5.1, below
  Vite's floor). `public/build` is gitignored, so a pull never updates it and
  the new arbitrary values — `min-h-[2.125rem]`, `leading-[1.0625rem]`,
  `text-[28px]` — would otherwise have no rules at all. All confirmed present
  in `app-D3K1wMF4.css`, which serves 200.
- `queue:restart`.

Verified on staging, not just locally: all six cached panels build, write and
**read back** through the real Redis store with a flat payload and a live
`Carbon` for `generated_at` — the failure mode that only a serialising store
can show. `attention()` builds for an admin, and the registry hands that viewer
all four grievance rows.

`ActionCenterFeature` and `InventoryFeature` both resolve **false** on staging,
so the dashboard there shows five panels, not seven, and the R-100 rows have no
live surface yet. That is the zero-UI-trace rule working, not a deploy gap.
