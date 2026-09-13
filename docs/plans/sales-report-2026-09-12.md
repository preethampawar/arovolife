# Sales Report — Implementation Plan

Date: 2026-09-12 · Author: planning session · Status: awaiting approval

Repo root for all paths below: `arovolife-code/app/` (the Laravel root).

---

## Goal

Admin staff and distributors can read a sales report derived from existing
`orders` / `order_items` data — a filterable register plus three aggregate
views — with CSV export, no new tables, and no new figures invented. Admin sees
the whole book and can slice by distributor; a distributor sees exactly the
orders attributed to them that are not self-consumption, i.e. the same set the
existing **My Sales** screen shows, and never another distributor's rows.

## Non-goals

- No new database tables, columns or migrations. Every figure is read from
  `orders`, `order_items` and `bv_ledger_entries` as they exist today.
- No cost price, COGS, company margin or profit figure on the distributor side.
- No projected, estimated or annualised earnings anywhere — the project's
  compliance rules forbid income projections, and an "estimated commission"
  column is exactly that.
- No charts. Tables + CSV only, matching the inventory reports.
- No scheduled/emailed reports, no PDF, no saved filters.
- No change to `My Sales` (`shop.orders.sales`); the report links to it, the
  two read the same scope, neither replaces the other.

## Current behaviour (what already exists)

| Thing | Where |
|---|---|
| Order model, statuses, `bvTotalPaise()`, `salesBvStatus()` | `app/Modules/Commerce/Models/Order.php` |
| Attribution column — the distributor who sold it | `orders.attributed_distributor_id` |
| Self-consumption flag | `orders.self_consumption` (bool) |
| Line BV | `OrderItem::lineBvPaise()` = `qty * bv_paise` |
| Distributor "My Sales" list | `MyOrdersController::mySales()`, route `orders.sales` |
| Order ownership policy | `app/Modules/Commerce/Policies/OrderPolicy.php` |
| **The report pattern to copy** | `app/Modules/Inventory/Http/Controllers/Admin/AdminInventoryReportController.php` — one method per report, private `render()` / `exportCsv()` / `dateRange()` / `money()` helpers, generic view |
| Generic report table view | `resources/views/admin/inventory/reports/show.blade.php` |
| Report index cards | `resources/views/admin/inventory/reports/index.blade.php` |
| Role/permission definitions | `database/seeders/RolesAndPermissionsSeeder.php` |
| Admin sidebar (single source of truth) | `app/Modules/Shared/Support/AdminNavigation.php` |
| CSV injection guard | `App\Modules\Shared\Support\Csv::safe()` |
| Playwright fixtures (`adminPage`, `distributorPage`) | `tests/Browser/fixtures.js` |

## Architecture decisions

**D1 — New permission `sales.report.view`, not a reused one.**
Alternatives: reuse `audit.read` (would hand revenue to `admin-compliance`)
or `inventory.view` (semantically wrong). Chosen: a new permission in
`SHARED`, held by `admin-operations` and `admin-finance` only, per the R-17
separation-of-duties pattern already documented in the seeder. Compliance has
no business in revenue figures.

**D2 — One controller per audience, one shared service.**
`SalesReportService` owns every query and, critically, the scoping. The two
controllers (admin, distributor) differ only in the scope they pass in and the
columns they request. This means the distributor scope rule is written once.
Alternative — duplicating queries per controller — is how the two screens
drift apart.

**D3 — Scope is a value object, not a nullable distributor id.**
`SalesScope` carries either `all()` (admin) or `distributor(int $id)`. The
service *requires* one; there is no default, so a future controller cannot
forget to pass it and silently get the whole book. This is the same reasoning
the existing `OrderPolicy` docblock gives for being a structural backstop.

**D4 — "Sales" = orders that reached payment.**
Counted statuses: `paid`, `ready_to_ship`, `shipped`, `delivered`, `confirmed`.
Excluded: `draft`, `placed` (unpaid), `cancelled`. Refunded orders
(`refunded`, `refund_approved`) are **included with a negative-flagged status
column** in the register but **excluded from aggregate totals**, so the
register reconciles to the order list and the summary reconciles to money
received. This rule lives in one place: `SalesReportService::COUNTED_STATUSES`.

**D5 — Distributor scope excludes self-consumption.**
`attributed_distributor_id = :id AND self_consumption = false`. Identical
predicate to `MyOrdersController::mySales()`. A distributor's own purchases are
not their sales.

**D6 — Distributor sees order value + BV; never cost, margin or commission.**
Per D6 the distributor column set is: date, order no, customer name, items,
net value, BV, BV status, order status. The admin column set adds GST,
discount, shipping, payment method and distributor name/ADN.

**D7 — Reuse the inventory report view rather than write new Blade.**
A new generic view `resources/views/admin/reports/table.blade.php` is created
(a near-copy of the inventory one with the warehouse filter swapped for the
sales filter set) rather than parameterising the inventory view, because the
filter controls differ and overloading that view would couple two modules.

## Permission matrix

This table is the source of truth for both the gating code and the Playwright
specs. `developer` and `admin` bypass everything via `Gate::before` in
`AppServiceProvider` and are not listed per-row.

| # | Capability | Route name | Customer (no distributor) | Distributor | admin-operations | admin-finance | admin-compliance |
|---|---|---|---|---|---|---|---|
| P1 | Open admin reports index | `admin.reports.sales.index` | ✗ 403 | ✗ 403 | ✓ | ✓ | ✗ 403 |
| P2 | Admin sales register | `admin.reports.sales.register` | ✗ 403 | ✗ 403 | ✓ | ✓ | ✗ 403 |
| P3 | Admin summary by period | `admin.reports.sales.by-period` | ✗ 403 | ✗ 403 | ✓ | ✓ | ✗ 403 |
| P4 | Admin sales by product | `admin.reports.sales.by-product` | ✗ 403 | ✗ 403 | ✓ | ✓ | ✗ 403 |
| P5 | Admin sales by distributor | `admin.reports.sales.by-distributor` | ✗ 403 | ✗ 403 | ✓ | ✓ | ✗ 403 |
| P6 | Filter by arbitrary `distributor_id` | query param on P2–P4 | ✗ | ✗ ignored | ✓ | ✓ | ✗ |
| P7 | Admin CSV export | `?export=csv` on P2–P5 | ✗ 403 | ✗ 403 | ✓ | ✓ | ✗ 403 |
| P8 | See admin sidebar item "Sales Reports" | `AdminNavigation` | ✗ hidden | ✗ hidden | ✓ shown | ✓ shown | ✗ hidden |
| P9 | Open own sales report | `reports.sales` | ✗ 403 | ✓ own only | n/a | n/a | n/a |
| P10 | Own report CSV export | `?export=csv` on P9 | ✗ 403 | ✓ own only | n/a | n/a | n/a |
| P11 | See "Sales report" link on My Sales page | `shop/orders/sales.blade.php` | ✗ hidden | ✓ shown | n/a | n/a | n/a |
| P12 | See another distributor's rows | any | ✗ | ✗ never | ✓ | ✓ | ✗ |

**P6 is the one to get right:** a distributor passing `?distributor_id=<other>`
to their own report must be ignored, not honoured. The scope is taken from the
authenticated user, never from the request, on the distributor route.

## File changes

| # | Path | New/Mod | Change |
|---|---|---|---|
| F1 | `database/seeders/RolesAndPermissionsSeeder.php` | Mod | Add `sales.report.view` to `SHARED` |
| F2 | `app/Modules/Commerce/Support/SalesScope.php` | New | Scope value object |
| F3 | `app/Modules/Commerce/Services/SalesReportService.php` | New | All four queries + counted-status rule |
| F4 | `app/Modules/Commerce/Http/Controllers/Admin/AdminSalesReportController.php` | New | 5 actions + render/export helpers |
| F5 | `app/Modules/Commerce/Http/Controllers/Storefront/MySalesReportController.php` | New | 1 action, scope from auth user |
| F6 | `app/Modules/Shared/Support/SalesReportCsv.php` | New | Shared CSV streamer (used by F4 and F5) |
| F7 | `routes/web.php` | Mod | Admin route group + distributor route |
| F8 | `app/Modules/Shared/Support/AdminNavigation.php` | Mod | Nav item in `commerceItems()` |
| F9 | `resources/views/admin/reports/index.blade.php` | New | Report cards |
| F10 | `resources/views/admin/reports/table.blade.php` | New | Generic filtered table + CSV button |
| F11 | `resources/views/shop/reports/sales.blade.php` | New | Distributor report |
| F12 | `resources/views/shop/orders/sales.blade.php` | Mod | Add link to the report |
| F13 | `tests/Feature/SalesReportScopeTest.php` | New | Pest: scope + status rules |
| F14 | `tests/Feature/SalesReportPermissionTest.php` | New | Pest: every matrix row |
| F15 | `tests/Browser/sales-report.spec.js` | New | Playwright, both directions |

---

### F1 — `database/seeders/RolesAndPermissionsSeeder.php`

Insert into the `SHARED` const array, immediately **after** the
`'inventory.view' => [...]` entry and before `'action.center.view'`:

```php
// Added 2026-09-12 with the sales report. Operations reads what shipped
// and to whom; finance reads the revenue it has to account for. Compliance
// does not hold it — a revenue book is not a compliance surface, and R-17
// keeps each scoped role to the queues it actually answers for.
'sales.report.view' => ['admin-operations', 'admin-finance'],
```

No other edit to this file. The seeder is additive (`firstOrCreate` +
`givePermissionTo`), so it is safe to re-run: `php artisan db:seed --class=RolesAndPermissionsSeeder`.

### F2 — `app/Modules/Commerce/Support/SalesScope.php` (new)

```php
<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Support;

/**
 * Who a sales report is being run for.
 *
 * The service takes one of these rather than a nullable distributor id, so
 * "no scope" is not expressible: a future caller cannot forget the argument
 * and silently receive the whole book. Same reasoning as OrderPolicy — the
 * correctness should not rest on each controller author remembering a
 * `where` clause.
 */
final readonly class SalesScope
{
    private function __construct(
        public ?int $distributorId,
        public bool $excludeSelfConsumption,
    ) {}

    /** The whole book. Admin surfaces only. */
    public static function all(): self
    {
        return new self(null, false);
    }

    /**
     * Orders this distributor personally sold: attributed to them and not
     * their own purchase (D5). Identical predicate to MyOrdersController::mySales.
     */
    public static function distributor(int $distributorId): self
    {
        return new self($distributorId, true);
    }

    /** Admin filtering the whole book down to one distributor (P6). */
    public static function adminFilteredTo(int $distributorId): self
    {
        return new self($distributorId, false);
    }

    public function isAll(): bool
    {
        return $this->distributorId === null;
    }
}
```

### F3 — `app/Modules/Commerce/Services/SalesReportService.php` (new)

Namespace `App\Modules\Commerce\Services`. Final class, no constructor.

Constants:

```php
/** D4 — an order is a sale once money arrived. */
public const COUNTED_STATUSES = [
    Order::STATUS_PAID,
    Order::STATUS_READY_TO_SHIP,
    Order::STATUS_SHIPPED,
    Order::STATUS_DELIVERED,
    Order::STATUS_CONFIRMED,
];

/** Shown in the register, excluded from every total. */
public const REFUNDED_STATUSES = [
    Order::STATUS_REFUND_APPROVED,
    Order::STATUS_REFUNDED,
];
```

Private base query — **the only place the scope is applied**:

```php
/**
 * @param  array{from: ?Carbon, to: ?Carbon, status: string, variantId: ?int}  $filters
 */
private function baseQuery(SalesScope $scope, array $filters, bool $includeRefunded): Builder
{
    return Order::query()
        ->when($scope->distributorId !== null,
            fn (Builder $q) => $q->where('orders.attributed_distributor_id', $scope->distributorId))
        ->when($scope->excludeSelfConsumption,
            fn (Builder $q) => $q->where('orders.self_consumption', false))
        ->whereIn('orders.status', $includeRefunded
            ? [...self::COUNTED_STATUSES, ...self::REFUNDED_STATUSES]
            : self::COUNTED_STATUSES)
        ->when($filters['from'] !== null, fn (Builder $q) => $q->where('orders.placed_at', '>=', $filters['from']))
        ->when($filters['to'] !== null, fn (Builder $q) => $q->where('orders.placed_at', '<=', $filters['to']))
        ->when($filters['status'] !== '', fn (Builder $q) => $q->where('orders.status', $filters['status']))
        ->when($filters['variantId'] !== null, fn (Builder $q) => $q->whereHas(
            'items', fn (Builder $i) => $i->where('product_variant_id', $filters['variantId'])));
}
```

Public methods — each returns `Illuminate\Support\Collection` of flat row
arrays, keys matching the column `key`s the controller declares:

1. `register(SalesScope $scope, array $filters): Collection`
   - `$this->baseQuery($scope, $filters, includeRefunded: true)`
     `->with(['items', 'customer', 'distributor.user', 'bvLedgerEntries'])`
     `->latest('orders.placed_at')->limit(2000)->get()`
   - Row keys: `placed_at`, `order_no`, `customer`, `distributor`, `adn`,
     `items` (sum of `qty`), `gross` (`subtotal_paise`), `discount`,
     `gst`, `shipping`, `net` (`total_paise`), `bv` (`bvTotalPaise()`),
     `bv_status` (`salesBvStatus()['label']`), `status`, `payment_method`.
   - Money values formatted with the controller's `money()`; `bv` formatted as
     `number_format($paise / 100, 2)` with no ₹ symbol (BV is points, not rupees).
   - `limit(2000)` matches the inventory reports' `limit(1000)` convention;
     note it in the view footer when the cap is hit.

2. `byPeriod(SalesScope $scope, array $filters, string $granularity): Collection`
   - `$granularity` is `'day'` or `'month'`; anything else → `'month'`.
   - Group by `DATE_FORMAT(orders.placed_at, '%Y-%m-%d')` or `'%Y-%m'`.
   - Aggregate on the non-refunded query (`includeRefunded: false`).
   - Row keys: `period`, `orders` (count), `items`, `gross`, `discount`,
     `gst`, `shipping`, `net`, `bv`. Order ascending by `period`.
   - `items` and `bv` require joining `order_items`:
     `->join('order_items', 'order_items.order_id', '=', 'orders.id')`
     then `SUM(order_items.qty)` and `SUM(order_items.qty * order_items.bv_paise)`.
     **Because that join fans out order rows, the order-level money columns must
     use `SUM(DISTINCT ...)` semantics — implement them as a separate aggregate
     query on the un-joined builder and merge on `period`.** Two queries, one
     for order-level money, one for item-level qty/BV. Do not try to do it in
     one joined query; the totals will be wrong.

3. `byProduct(SalesScope $scope, array $filters): Collection`
   - Join `order_items` on the scoped order ids, group by
     `order_items.product_variant_id`.
   - Row keys: `sku` (`variant_sku_snapshot`), `product`
     (`product_name_snapshot`), `qty` (`SUM(qty)`), `gross`
     (`SUM(taxable_value_paise)`), `gst` (`SUM(gst_paise)`), `net`
     (`SUM(line_total_paise)`), `bv` (`SUM(qty * bv_paise)`),
     `orders` (`COUNT(DISTINCT orders.id)`). Order by `net` desc.
   - Use the snapshot columns, not the live product name — the report must
     show what was sold at the time, and the catalog can be renamed.

4. `byDistributor(SalesScope $scope, array $filters): Collection`
   - Admin only. Group by `orders.attributed_distributor_id`, left-join
     `distributors` and `users` for the name and ADN.
   - Row keys: `adn`, `distributor`, `orders`, `items`, `net`, `bv`.
     Order by `net` desc. Same two-query fan-out care as `byPeriod`.
   - Orders with `attributed_distributor_id IS NULL` are grouped into a single
     row labelled `— (direct)` with an empty `adn`.

Every money value is returned as an **int of paise**; formatting happens in
the controller, so the CSV and the HTML cannot disagree on rounding.

### F4 — `app/Modules/Commerce/Http/Controllers/Admin/AdminSalesReportController.php` (new)

Namespace `App\Modules\Commerce\Http\Controllers\Admin`. `final class ... extends Controller`.
Constructor promotes `private SalesReportService $reports`.

Actions:

- `index(): View` → `view('admin.reports.index')`
- `register(Request $r): View|StreamedResponse`
- `byPeriod(Request $r): View|StreamedResponse`
- `byProduct(Request $r): View|StreamedResponse`
- `byDistributor(Request $r): View|StreamedResponse`

Each non-index action:

```php
$scope = $this->scopeFrom($request);
$filters = $this->filters($request);
$rows = $this->reports->register($scope, $filters);   // or the matching method
return $this->render($request, 'Sales register', 'register', $columns, $rows);
```

Private helpers (copy the shape from `AdminInventoryReportController`):

```php
/** Admin may narrow to one distributor (P6); default is the whole book. */
private function scopeFrom(Request $request): SalesScope
{
    $id = $request->integer('distributor_id');

    return $id > 0 ? SalesScope::adminFilteredTo($id) : SalesScope::all();
}

/** @return array{from: ?Carbon, to: ?Carbon, status: string, variantId: ?int} */
private function filters(Request $request): array

private function dateRange(Request $request): array   // identical to the inventory one
private function money(int $paise): string            // '₹'.number_format($paise / 100, 2)
private function bv(int $paise): string               // number_format($paise / 100, 2)

private function render(Request $request, string $title, string $slug,
    array $columns, iterable $rows): View|StreamedResponse
{
    if ($request->query('export') === 'csv') {
        return SalesReportCsv::stream("sales-{$slug}", $columns, $rows);
    }

    return view('admin.reports.table', [
        'title' => $title, 'slug' => $slug, 'columns' => $columns, 'rows' => $rows,
        'statuses' => SalesReportService::COUNTED_STATUSES,
        'filters' => $request->only(['date_from', 'date_to', 'status', 'distributor_id', 'granularity']),
        'showDistributorFilter' => true,
        'backRoute' => 'admin.reports.sales.index',
    ]);
}
```

Column sets (exact `key`/`label`/`align`, in this order):

*register* — `placed_at` Date · `order_no` Order · `customer` Customer ·
`adn` ADN · `distributor` Distributor · `items` Items(right) ·
`gross` Gross(right) · `discount` Discount(right) · `gst` GST(right) ·
`shipping` Shipping(right) · `net` Net(right) · `bv` BV(right) ·
`bv_status` BV status · `status` Status · `payment_method` Payment

*by-period* — `period` Period · `orders` Orders(right) · `items` Items(right) ·
`gross` Gross(right) · `discount` Discount(right) · `gst` GST(right) ·
`shipping` Shipping(right) · `net` Net(right) · `bv` BV(right)

*by-product* — `sku` SKU · `product` Product · `qty` Units(right) ·
`orders` Orders(right) · `gross` Taxable(right) · `gst` GST(right) ·
`net` Net(right) · `bv` BV(right)

*by-distributor* — `adn` ADN · `distributor` Distributor · `orders` Orders(right) ·
`items` Items(right) · `net` Net(right) · `bv` BV(right)

### F5 — `app/Modules/Commerce/Http/Controllers/Storefront/MySalesReportController.php` (new)

```php
/**
 * The distributor's own sales report. The scope comes from the authenticated
 * user and nothing else: a `distributor_id` in the query string is ignored,
 * not honoured (permission matrix P6). There is no code path here that can
 * produce another distributor's rows.
 */
final class MySalesReportController extends Controller
{
    public function __construct(private readonly SalesReportService $reports) {}

    public function index(Request $request): View|StreamedResponse
    {
        $distributor = $request->user()?->distributor;

        abort_unless($distributor !== null, 403);

        $scope = SalesScope::distributor((int) $distributor->id);
        $filters = $this->filters($request);   // same shape as F4, minus distributor_id

        $rows = $this->reports->register($scope, $filters);
        $summary = $this->reports->byPeriod($scope, $filters, 'month');
        $products = $this->reports->byProduct($scope, $filters);

        if ($request->query('export') === 'csv') {
            return SalesReportCsv::stream('my-sales', self::COLUMNS, $rows);
        }

        return view('shop.reports.sales', compact('rows', 'summary', 'products') + [
            'filters' => $request->only(['date_from', 'date_to', 'status']),
        ]);
    }
}
```

`self::COLUMNS` is the distributor column set (D6 — **no** `gross`/`gst`/
`shipping`/`discount`/`payment_method`, and no `distributor`/`adn` columns,
since every row is theirs): `placed_at` Date · `order_no` Order ·
`customer` Customer · `items` Items(right) · `net` Order value(right) ·
`bv` BV(right) · `bv_status` BV status · `status` Status.

### F6 — `app/Modules/Shared/Support/SalesReportCsv.php` (new)

Lift `exportCsv()` from `AdminInventoryReportController` verbatim into a static
helper so both controllers stream identically. Signature:

```php
final class SalesReportCsv
{
    /**
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public static function stream(string $filename, array $columns, iterable $rows): StreamedResponse
}
```

Body identical to the inventory version, including the `Csv::safe($value)` call
on every cell (CSV-injection guard — do not drop it) and the Carbon/bool/scalar
coercion. Filename `"{$filename}.csv"`, header `['Content-Type' => 'text/csv']`.

Do **not** refactor `AdminInventoryReportController` to use it in this change.

### F7 — `routes/web.php`

**Admin block.** Insert immediately after the existing
`Route::middleware('can:commerce.order.manage')->group(...)` block that ends at
the line `});` following `commerce.orders.cancel` (currently ~line 413):

```php
    // Sales reports — read-only revenue views over existing orders. Held by
    // admin-operations (what shipped) and admin-finance (what it earned);
    // admin-compliance does not hold it (R-17, D1).
    Route::prefix('reports/sales')->name('reports.sales.')->middleware('can:sales.report.view')->group(function (): void {
        Route::get('/', [AdminSalesReportController::class, 'index'])->name('index');
        Route::get('/register', [AdminSalesReportController::class, 'register'])->name('register');
        Route::get('/by-period', [AdminSalesReportController::class, 'byPeriod'])->name('by-period');
        Route::get('/by-product', [AdminSalesReportController::class, 'byProduct'])->name('by-product');
        Route::get('/by-distributor', [AdminSalesReportController::class, 'byDistributor'])->name('by-distributor');
    });
```

Add `use App\Modules\Commerce\Http\Controllers\Admin\AdminSalesReportController;`
to the import block at the top, in alphabetical position among the other
`Admin\Admin*` imports.

**Distributor route.** Insert immediately after the existing line
`Route::get('/orders/sales/{orderNo}', [MyOrdersController::class, 'salesShow'])->name('orders.sales.show');`
(currently line 1103), inside the same auth group:

```php
    Route::get('/reports/sales', [MySalesReportController::class, 'index'])->name('reports.sales');
```

Add `use App\Modules\Commerce\Http\Controllers\Storefront\MySalesReportController;`
alongside the existing `MyOrdersController` import.

Do not add a permission middleware to the distributor route — the `abort_unless`
on the distributor record in F5 is the gate, matching `mySales`.

### F8 — `app/Modules/Shared/Support/AdminNavigation.php`

In `commerceItems()`, insert as the **last** element of the returned array,
after the BV Ledger block:

```php
            // Gated on the same permission as the routes, so a role that
            // cannot open the report is never shown the link (P8).
            ...($user?->can('sales.report.view')
                ? [['route' => 'admin.reports.sales.index', 'label' => 'Sales Reports', 'icon' => 'chart-column', 'prefix' => 'admin.reports.sales']]
                : []),
```

No other edit to this file.

### F9 — `resources/views/admin/reports/index.blade.php` (new)

Copy `resources/views/admin/inventory/reports/index.blade.php` structure
exactly. Title/heading `Sales reports`. `$reports` array:

```php
['route' => 'admin.reports.sales.register',        'label' => 'Sales register',       'hint' => 'One row per order with gross, discount, GST, net and BV.'],
['route' => 'admin.reports.sales.by-period',       'label' => 'Sales by period',      'hint' => 'Daily or monthly totals — orders, units, net value and BV.'],
['route' => 'admin.reports.sales.by-product',      'label' => 'Sales by product',     'hint' => 'Units and value per SKU over the selected range.'],
['route' => 'admin.reports.sales.by-distributor',  'label' => 'Sales by distributor', 'hint' => 'Orders, net value and BV per attributing distributor.'],
```

### F10 — `resources/views/admin/reports/table.blade.php` (new)

Copy `resources/views/admin/inventory/reports/show.blade.php` and change only
the filter form. Replace the warehouse `<select>` with:

- `<input type="date" name="date_from">` and `name="date_to"`, values from `$filters`
- `<select name="status">` — blank option `All paid statuses`, then one option
  per `$statuses` entry, `@selected(($filters['status'] ?? '') === $status)`
- `@if($showDistributorFilter)` `<input type="number" name="distributor_id"
  placeholder="Distributor ID" value="{{ $filters['distributor_id'] ?? '' }}">`
- on the `by-period` slug only: `<select name="granularity">` with `month`/`day`

Keep the Export CSV anchor (`request()->fullUrlWithQuery(['export' => 'csv'])`),
the table markup, the Carbon formatting and the empty-state row unchanged.
Back-link uses `route($backRoute)`.

### F11 — `resources/views/shop/reports/sales.blade.php` (new)

Extend the same layout `shop.orders.sales.blade.php` extends (check its first
line and match it). Three sections in this order:

1. Heading `My sales report`, with a back link to `route('orders.sales')`.
2. Filter form: `date_from`, `date_to`, `status`; Export CSV anchor.
3. **Summary cards** from `$summary` (last row = current month): orders, units,
   net value, BV.
4. **By product** table from `$products`: SKU, product, units, net, BV.
5. **Register** table from `$rows` using the distributor column set.

No `distributor_id` input anywhere on this page. No gross/GST/discount/
shipping/margin columns (D6).

### F12 — `resources/views/shop/orders/sales.blade.php`

Next to the existing `route('orders.sales')` link block at line ~23, add:

```blade
<a href="{{ route('reports.sales') }}"
   class="px-4 py-2 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Sales report</a>
```

Match the sibling button's classes exactly rather than the snippet above if
they differ.

### F13 — `tests/Feature/SalesReportScopeTest.php` (new, Pest)

Create with `php artisan make:test --pest SalesReportScopeTest`. Cover:

- distributor scope returns only orders with their `attributed_distributor_id`
- distributor scope excludes `self_consumption = true` orders
- `draft`, `placed` and `cancelled` orders never appear
- refunded orders appear in `register()` but not in `byPeriod()` totals
- `byPeriod` money totals are not multiplied by the `order_items` join
  (**seed an order with 3 line items and assert `net` equals the order total,
  not 3×** — this is the single most likely bug in the whole change)
- `byProduct` uses snapshot names: rename the catalog product after the order
  and assert the report still shows the old name

### F14 — `tests/Feature/SalesReportPermissionTest.php` (new, Pest)

One assertion per permission-matrix row P1–P12, server-side. For each admin
route: `admin-operations` 200, `admin-finance` 200, `admin-compliance` 403,
plain distributor 403, plain customer 403. Plus: a distributor hitting
`reports.sales?distributor_id=<other>` gets only their own rows (P6).

### F15 — `tests/Browser/sales-report.spec.js` (new, Playwright)

See test plan below.

---

## Slices

| Slice | Title | Files | Depends on | Model |
|---|---|---|---|---|
| S1 | Permission + scope object | F1, F2 | — | Sonnet |
| S2 | Sales report service (all four queries) | F3 | S1 | **Opus** |
| S3 | Controllers, routes, CSV helper, nav | F4, F5, F6, F7, F8 | S2 | **Opus** |
| S4 | Views | F9, F10, F11, F12 | S3 | Sonnet |
| S5 | Pest tests | F13, F14 | S3 | **Opus** |
| S6 | Playwright spec | F15 | S4 | Sonnet |

S1 first. S2 next. S3 next. **S4 and S5 run in parallel.** S6 after S4.

Rationale for the model split: S2 carries the aggregation correctness (the
join fan-out trap) and S3 carries the authorization, so both get Opus. S5 is
test *design* against a permission matrix, which is judgement, so Opus too.
S1, S4 and S6 are transcription of an explicit spec — Sonnet.

## Test plan (Playwright — `tests/Browser/sales-report.spec.js`)

Use `import { test } from './fixtures.js'` for `adminPage` / `distributorPage`.
Sequential (`workers: 1` is already set). Prefer `getByRole` / `getByLabel`.

| # | Matrix row | Scenario | Expect |
|---|---|---|---|
| T1 | P1, P8 | `adminPage` → `/admin` | Sidebar shows `Sales Reports` link under Commerce |
| T2 | P1 | `adminPage` → `/admin/reports/sales` | 200, four report cards visible by name |
| T3 | P2 | `adminPage` → register | Table renders; header row contains Gross, GST, Net, BV |
| T4 | P3 | by-period with `granularity=month` | Period column present; at least one row or the empty-state text |
| T5 | P4 | by-product | SKU + Units columns present |
| T6 | P5 | by-distributor | ADN column present |
| T7 | P7 | click Export CSV on register | Download event fires; filename `sales-register.csv` |
| T8 | P6 | register with `?distributor_id=<X>` | Every Distributor cell equals X's name |
| T9 | P9, P11 | `distributorPage` → `/orders/sales` | `Sales report` link visible; click → `/reports/sales` 200 |
| T10 | P9, D6 | distributor report page | Shows Order value + BV columns; **asserts GST, Gross, Discount, Margin headers are absent** |
| T11 | P12, P6 | distributor → `/reports/sales?distributor_id=<other>` | Page 200 but every row's order no. is one of their own; no other distributor's order appears |
| T12 | P1 | `distributorPage` → `/admin/reports/sales/register` | 403 or redirect to login/dashboard |
| T13 | P8 | `distributorPage` → any page | No `Sales Reports` link anywhere in the DOM |
| T14 | P10 | distributor Export CSV | Download fires; filename `my-sales.csv` |
| T15 | validation | register with `date_from=2030-01-01&date_to=2020-01-01` | Renders the empty-state row, no 500 |
| T16 | edge | register with `?export=csv` and zero matching rows | CSV downloads with header row only, no 500 |

Roles the fixture does not yet cover (`admin-finance`, `admin-compliance`) are
asserted in **F14 Pest tests** rather than Playwright, since `fixtures.js` has
no scoped-role login helper. Do not add one in this change.

## Acceptance criteria

- [ ] `php artisan db:seed --class=RolesAndPermissionsSeeder` runs clean and
      `sales.report.view` exists on `admin-operations` and `admin-finance` only
      (plus `admin`/`developer`).
- [ ] `php artisan route:list --path=reports` lists all six new routes with the
      expected middleware.
- [ ] Every row of the permission matrix has a passing assertion in F14.
- [ ] An order with 3 line items contributes its own total once to
      `byPeriod` — not three times (F13).
- [ ] Distributor report page source contains no `gst`, `discount`, `margin`
      or `cost` column header.
- [ ] `vendor/bin/pint --dirty --format agent` clean.
- [ ] `vendor/bin/phpstan analyse` no new errors against the baseline.
- [ ] `php artisan test --compact` green.
- [ ] `npx playwright test tests/Browser/sales-report.spec.js` green.

Commands, in order:

```
php artisan db:seed --class=RolesAndPermissionsSeeder
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact --filter=SalesReport
npx playwright test tests/Browser/sales-report.spec.js
```

## Risks

| Risk | Mitigation |
|---|---|
| Join fan-out inflating money totals | D3/F3 mandates two queries; F13 asserts it explicitly |
| A distributor reaching another's rows via query param | Scope taken from auth user only (F5); T11 + F14 assert it |
| Compliance reading revenue | `sales.report.view` excluded from `admin-compliance` (F1); F14 asserts 403 |
| An earnings column reading as an income projection | Explicit non-goal; acceptance criterion checks the header absence |
| Large ranges timing out | `limit(2000)` on the register; aggregates are grouped, not row-by-row |
