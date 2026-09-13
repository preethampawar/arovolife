# List-page filters (admin + distributor) — Implementation Plan

Date: 2026-09-13
Status: draft (Phase 1–3 complete; Phase 4 pending)

## Goal

Every list page in the admin area and every authenticated distributor-facing
list page exposes a consistent, discoverable filter toolbar: a labelled GET
form with search / select / date-range controls appropriate to that page's
data, an Apply and a Clear action, removable chips for the filters currently
in force, and a paginator and export link that both carry the active filters.
Filters are declared once per page against real columns, applied through one
shared support class, and rendered by one shared Blade component — so a page
either has the toolbar or it is on the explicit exclusion list, with nothing
in between.

## Non-goals

- No new sorting UI. (Column sorting is a separate concern; pages that already
  sort keep their behaviour.)
- No saved/named filter presets, no per-user default filters.
- No JavaScript filter framework, no Livewire, no Inertia. Plain GET forms.
- No change to any query's *scope*: a filter narrows the rows a viewer could
  already see, never widens them.
- No change to export file formats. Exports only start honouring the filters
  that are already in the URL.
- Public (unauthenticated) pages other than `shop.index` are out of scope.
- No redesign of the tables themselves (columns, badges, empty states).

## Architecture decisions

**A1 — One shared support class + one anonymous Blade component, not per-page
bespoke markup.** Alternatives: (a) leave each page hand-rolling its own form —
that is the status quo and is exactly what produced "partially implemented,
fully implemented, or not at all"; (b) a class-based Blade component per filter
type — more files, no benefit, since the markup is one toolbar. Chosen: a
`ListFilters` value object built in the controller and an anonymous
`<x-filter-bar>` that renders it. Each page's change becomes ~10 declarative
lines, which is what makes a 100-page rollout tractable and reviewable.

**A2 — Filter definitions live in the controller, next to the query.** Not in
a config file or a model attribute. A filter that drifts from the query it
filters is the failure mode worth designing against; keeping them adjacent
makes drift visible in one diff.

**A3 — Declarative auto-apply for the trivial cases, explicit closures for the
rest.** A field may declare `column:` (equals), `columns:` (LIKE across
several) or `dateColumn:` (range) and `ListFilters::apply()` applies it. Any
page whose filter needs a join, a raw expression, or a service call omits
those and applies the value itself. No magic that a page cannot opt out of.

**A4 — Explicit Apply button; no auto-submit on change.** Auto-submitting a
select is nicer to use but makes a multi-control toolbar race itself and makes
Playwright assertions timing-dependent. One Apply, one Clear, deterministic.

**A5 — Light-first Tailwind utilities only, no `dark:` variants.** The admin
dark theme is implemented globally in `resources/css/app.css` as
`html.dark .bg-white { … }` class overrides (see
`docs/plans/admin-dark-theme-2026-09-13.md`). A component written in the
standard light utilities is themed for free; a component sprinkled with
`dark:` would fight that system.

**A6 — Every paginator gets `withQueryString()`.** 24 paginators currently
drop the query string, so page 2 of a filtered list silently shows unfiltered
data. This is a correctness bug independent of the new toolbar and is fixed in
its own slice.

**A7 — Distributor-facing filters are applied to the already-scoped query.**
The scoping expression is never itself derived from a request parameter. No
distributor-facing filter may take an ADN, a user id, or a distributor
identifier. (CLAUDE.md hard rule 3 — earnings and wallet data are own-only.)

## Permission matrix

Filters introduce **no new routes and no new actions**. Every filter control
is rendered on a page the viewer has already been authorised to open, and
narrows a query that is already scoped. The matrix therefore records, per page
family, who may open the page at all and what the filter is permitted to reach.

| Capability | Guest | Distributor | admin-* staff | Notes |
|---|---|---|---|---|
| Open any `/admin/*` list page + its filter bar | deny (redirect to login) | deny (403) | allow, per existing per-page permission | unchanged; `admin` middleware |
| Filter an admin list across all distributors | deny | deny | allow | admin queries are global by design |
| Open a distributor list page (`/orders`, `/income/*`, `/my/*`, …) | deny (redirect) | allow, own data only | n/a | unchanged `auth` middleware |
| Filter a distributor list | deny | allow, **within own scope only** | n/a | A7 — no ADN/user-id filter offered or accepted |
| Filter widening scope beyond own data | deny | **deny** | n/a | enforced by the scope guard preceding the filter, asserted by test |
| Export a list with filters applied | deny | allow, own data only | allow | export re-reads the same `ListFilters` |
| Paginate a filtered list | deny | allow, filters preserved | allow, filters preserved | A6 |

## File changes

*(Foundation slice; the per-page rollout table follows in §File changes —
rollout.)*

| # | Path | New/Modified | Change summary |
|---|---|---|---|
| 1 | `app/app/Modules/Shared/Support/FilterField.php` | New | Value object describing one filter control. |
| 2 | `app/app/Modules/Shared/Support/ListFilters.php` | New | Reads the request, holds values, auto-applies to a query, exposes chips/clear/query helpers. |
| 3 | `app/resources/views/components/filter-bar.blade.php` | New | Anonymous component rendering a `ListFilters` as a labelled GET toolbar + chips. |
| 4 | `app/tests/Unit/Shared/ListFiltersTest.php` | New | Unit coverage for parsing, apply, chips, clear URLs. |
| 5 | `app/tests/Browser/list-filters.spec.js` | New | Playwright coverage (see Test plan). |
| 6 | `docs/plans/list-page-filters-2026-09-13.md` | New | This document. |

### 1. `app/app/Modules/Shared/Support/FilterField.php`

`declare(strict_types=1);`, namespace `App\Modules\Shared\Support`, `final
class FilterField`. Constructor is private; construction is through named
static factories. Public readonly properties:

```php
public readonly string $key;
public readonly string $type;          // text|select|date_range|month|bool
public readonly string $label;
/** @var array<string,string> value => label */
public readonly array $options;
public readonly ?string $placeholder;
public readonly ?string $column;       // auto-apply: equals
/** @var list<string> */
public readonly array $columns;        // auto-apply: LIKE across
public readonly ?string $dateColumn;   // auto-apply: whereBetween
public readonly string $fromKey;       // date_range only, default "{$key}_from"
public readonly string $toKey;         // date_range only, default "{$key}_to"
```

Factories (all return `self`):

```php
public static function text(string $key, string $label, ?string $placeholder = null, array $columns = []): self
public static function select(string $key, string $label, array $options, ?string $column = null, string $placeholder = 'All'): self
public static function dateRange(string $key, string $label, ?string $dateColumn = null, ?string $fromKey = null, ?string $toKey = null): self
public static function month(string $key, string $label, ?string $column = null): self
public static function boolean(string $key, string $label, ?string $column = null): self
```

Plus `public function requestKeys(): array` — returns `[$this->key]` for every
type except `date_range`, which returns `[$this->fromKey, $this->toKey]`.

### 2. `app/app/Modules/Shared/Support/ListFilters.php`

`declare(strict_types=1);`, namespace `App\Modules\Shared\Support`, `final class ListFilters`.

```php
/** @param list<FilterField> $fields  @param array<string,string> $values */
private function __construct(public readonly array $fields, public readonly array $values, private readonly string $path) {}

/** @param list<FilterField> $fields */
public static function make(Request $request, array $fields): self
```

`make()` walks `$fields`, and for each request key present on the request and
non-empty-string after `trim()`, stores it in `$values`. Values are stored as
trimmed strings. A `select` value not present in its `options` keys is
DISCARDED (never reaches the query) — this is the validation-failure path the
test plan exercises. A `date_range`/`month` value that does not match
`Y-m-d` / `Y-m` respectively is DISCARDED. A `boolean` is stored only when the
raw value is one of `1`, `true`, `on`, `yes`.

Public API:

```php
public function value(string $key): ?string        // null when absent
public function has(string $key): bool
public function any(): bool                        // $values !== []
/** @return array<string,string> */
public function toQuery(): array                   // the values, for appends()/export links
/** @return list<array{key:string,label:string,display:string,url:string}> */
public function chips(): array                     // one per active value; url = current URL minus that key
public function clearUrl(): string                 // current path with every filter key removed, other params kept
public function field(string $key): ?FilterField
public function apply(Builder $query): Builder     // auto-apply, see below
```

`apply()` rules, per active field:
- `text` with non-empty `columns`: `$query->where(fn ($q) => collect($columns)->each(fn ($c) => $q->orWhere($c, 'like', '%'.$value.'%')))`. Escape `%` and `_` in the value before interpolating.
- `select`/`month`/`boolean` with non-null `column`: `$query->where($column, $value)` (boolean casts to `1`).
- `date_range` with non-null `dateColumn`: `whereDate($col, '>=', $from)` and/or `whereDate($col, '<=', $to)` for whichever bound is present.
- A field with no column declaration is skipped — the controller applies it.

`chips()` and `clearUrl()` build URLs from `$path` + `request()->query()` so
that non-filter params (`tab`, `page` excluded) survive. `page` is always
dropped when a chip or Clear is followed — changing a filter must return to
page 1.

### 3. `app/resources/views/components/filter-bar.blade.php`

```blade
@props([
    'filters',              // ListFilters
    'action' => null,       // defaults to url()->current()
    'exports' => [],        // list<array{label:string,url:string}>
])
```

Renders, in order:

1. `<form method="GET" action="{{ $action ?? url()->current() }}" data-testid="filter-bar" class="mb-4 flex flex-wrap items-end gap-3">`
2. Hidden inputs re-emitting every current query param that is **not** a
   filter key and not `page` (so `tab=` survives an Apply).
3. One control per field. Every control is wrapped in
   `<label class="flex flex-col gap-1"><span class="text-xs font-medium text-gray-600">{{ $field->label }}</span> …control… </label>`
   so Playwright's `getByLabel()` resolves it. Control markup matches the
   existing admin idiom (see `resources/views/admin/inventory/reports/show.blade.php`):
   - `text`: `<input type="search" name="{{ $key }}" value="…" placeholder="…" class="rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">`
   - `select`: `<select>` with a first `<option value="">{{ $placeholder }}</option>` then the options, `@selected(...)`.
   - `date_range`: two `<input type="date">`, named `fromKey`/`toKey`, each with its own label text `"{{ $label }} from"` / `"{{ $label }} to"`.
   - `month`: `<input type="month">`.
   - `boolean`: `<input type="checkbox" value="1">`.
4. `<button type="submit" data-testid="filter-apply" class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Filter</button>`
5. When `$filters->any()`: `<a href="{{ $filters->clearUrl() }}" data-testid="filter-clear" class="px-4 py-2 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Clear</a>`
6. `$exports` rendered as trailing anchors, `data-testid="filter-export"`, each
   href already carrying `$filters->toQuery()` (the caller builds it with
   `request()->fullUrlWithQuery([...])`).
7. Below the form, when `$filters->any()`: a chip row,
   `<div data-testid="filter-chips">`, one `<a data-testid="filter-chip" href="{{ $chip['url'] }}">{{ $chip['label'] }}: {{ $chip['display'] }} ✕</a>` per chip.

Copy follows `.claude/skills/arovolife-ux-writing` — plain, no projections.
Button reads "Filter", not "Search"; the empty select option reads "All <thing>".

## Slices

| Slice | Title | Files (# refs) | Depends on | Model |
|---|---|---|---|---|
| S1 | Foundation: `FilterField`, `ListFilters`, `<x-filter-bar>`, unit tests | 1, 2, 3, 4 | — | Opus |
| S2 | `withQueryString()` on the 24 paginators that drop it | see §S2 table | — | Sonnet |
| S3 | Rollout: catalog / commerce / returns / payments / content | §rollout A | S1 | Sonnet |
| S4 | Rollout: inventory | §rollout A | S1 | Sonnet |
| S5 | Rollout: compensation + ops | §rollout B | S1 | Sonnet |
| S6 | Rollout: identity / compliance admin | §rollout C1 | S1 | Opus |
| S7 | Rollout: distributor-facing (scope-critical) | §rollout C2 | S1 | Opus |
| S8 | Playwright specs | 5 | S3–S7 | Opus |

S3–S7 are independent of each other and run concurrently once S1 lands.
S2 is independent of everything and runs alongside S1.

## Test plan

Spec file: `app/tests/Browser/list-filters.spec.js`, using the existing
`tests/Browser/fixtures.js` `adminPage` / `distributorPage` fixtures.

| # | Scenario | Role | Asserts |
|---|---|---|---|
| 1 | Toolbar present on every rolled-out admin page | admin | data-driven loop over the page list: `filter-bar` visible, ≥1 labelled control |
| 2 | Text search narrows the list | admin | row count after Apply ≤ before; every visible row contains the term |
| 3 | Select filter narrows the list | admin | all visible rows show the chosen status |
| 4 | Date-range filter narrows the list | admin | no row dated outside the range |
| 5 | Two filters compose | admin | URL carries both; rows satisfy both |
| 6 | Chip removes exactly one filter | admin | chip ✕ drops its key, keeps the other |
| 7 | Clear drops every filter | admin | URL back to bare path, full row count restored |
| 8 | Filters survive pagination | admin | page 2 URL retains every filter param; rows still match |
| 9 | Apply resets to page 1 | admin | `page` absent after Apply from page 3 |
| 10 | Invalid select value is discarded, page still 200 | admin | `?status=__nope__` → 200, unfiltered list, no chip |
| 11 | Export link carries the active filters | admin | href contains each active filter param |
| 12 | Toolbar present on every rolled-out distributor page | distributor | loop; `filter-bar` visible |
| 13 | Distributor filter narrows own data | distributor | rows match; count ≤ unfiltered |
| 14 | **Distributor list offers no cross-distributor filter** | distributor | no control named `adn`/`user_id`/`distributor_id` on any distributor page |
| 15 | **Injected scope param is ignored** | distributor | `?user_id=<other>&adn=<other>` returns the SAME row set as the bare page |
| 16 | Guest is refused | guest | every page in the list redirects to `/login` |
| 17 | Distributor is refused every admin page | distributor | 403 or redirect, and no `filter-bar` in the body |

Rows 14–17 are the permission-matrix directions; 10 is the validation-failure
path; 8/9 are the boundary cases.

Pest coverage (`app/tests/Unit/Shared/ListFiltersTest.php`): value parsing,
discard of out-of-range select/date/month, `apply()` per field type, chip URL
construction, `clearUrl()` preserving non-filter params, `page` always dropped.

## Acceptance criteria

- [ ] Every page in the rollout tables renders `data-testid="filter-bar"`.
- [ ] No page in the rollout tables still hand-rolls its own filter `<form method="GET">`.
- [ ] `grep -rn '\->paginate(' app --include='*.php'` — every hit either chains `withQueryString()`/`appends()` or is on the documented exclusion list.
- [ ] No distributor-facing page declares a filter field whose key is `adn`, `user_id`, `distributor_id`, or any other identity of another distributor.
- [ ] `docker exec arovolife-app ./vendor/bin/pint --dirty` clean.
- [ ] `docker exec arovolife-app ./vendor/bin/phpstan analyse --level=7` clean.
- [ ] `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact` green.
- [ ] `cd app && npm run build` succeeds (new Tailwind class combinations).
- [ ] `cd app && npx playwright test tests/Browser/list-filters.spec.js` green.
- [ ] Committed in Conventional-Commit slices, pushed, deployed to staging per `docs/runbooks/cloudways-deployment.md`.

## §S2 — paginators missing `withQueryString()`

Chain `->withQueryString()` directly after the `paginate(...)` call. No other change.

| # | Path (relative to `app/`) | Line | Call |
|---|---|---|---|
| 1 | `app/Modules/Catalog/Http/Controllers/Admin/AdminBannerController.php` | 32 | `->paginate(50),` |
| 2 | `app/Modules/Catalog/Http/Controllers/Admin/AdminCategoryController.php` | 28 | `->paginate(50);` |
| 3 | `app/Modules/Catalog/Http/Controllers/Admin/AdminProductController.php` | 36 | `->paginate(25);` |
| 4 | `app/Modules/Commerce/Http/Controllers/Admin/AdminCouponController.php` | 22 | `->paginate(25);` |
| 5 | `app/Modules/Commerce/Http/Controllers/Admin/AdminOrderController.php` | 39 | `->paginate(25);` |
| 6 | `app/Modules/Commerce/Http/Controllers/Storefront/MyOrdersController.php` | 30 | `->paginate(15);` |
| 7 | `app/Modules/Commerce/Http/Controllers/Storefront/MyOrdersController.php` | 56 | `->paginate(15);` |
| 8 | `app/Modules/Compensation/Http/Controllers/Admin/AdminMonthlyPayoutController.php` | 32 | `->paginate(20);` |
| 9 | `app/Modules/Compensation/Http/Controllers/Admin/AdminMonthlyPayoutController.php` | 40 | `->paginate(50);` |
| 10 | `app/Modules/Compensation/Http/Controllers/Admin/AdminWeeklyPayoutController.php` | 32 | `->paginate(20);` |
| 11 | `app/Modules/Compensation/Http/Controllers/Admin/AdminWeeklyPayoutController.php` | 42 | `->paginate(50);` |
| 12 | `app/Modules/Compensation/Http/Controllers/Admin/CompensationOverviewController.php` | 75 | `->paginate(50)` |
| 13 | `app/Modules/Compensation/Services/GenosBvLedgerService.php` | 77 | `->paginate($perPage);` |
| 14 | `app/Modules/Content/Http/Controllers/Admin/AdminAnnouncementController.php` | 51 | `->paginate(25),` |
| 15 | `app/Modules/Content/Http/Controllers/Admin/AdminContentPageController.php` | 23 | `->paginate(25);` |
| 16 | `app/Modules/Grievance/Http/Controllers/DistributorGrievanceController.php` | 53 | `->paginate(15),` |
| 17 | `app/Modules/Identity/Http/Controllers/NotificationController.php` | 31 | `->paginate(20),` |
| 18 | `app/Modules/Inventory/Http/Controllers/Admin/AdminPurchaseInvoiceController.php` | 32 | `->paginate(25);` |
| 19 | `app/Modules/Inventory/Http/Controllers/Admin/AdminPurchaseOrderController.php` | 31 | `->paginate(25);` |
| 20 | `app/Modules/Inventory/Http/Controllers/Admin/AdminStockAdjustmentController.php` | 25 | `->paginate(25);` |
| 21 | `app/Modules/Inventory/Http/Controllers/Admin/AdminStockTransferController.php` | 25 | `->paginate(25);` |
| 22 | `app/Modules/Inventory/Http/Controllers/Admin/AdminSupplierController.php` | 21 | `->paginate(25);` |
| 23 | `app/Modules/Inventory/Http/Controllers/Admin/AdminWarehouseController.php` | 22 | `->paginate(25);` |
| 24 | `app/Modules/Returns/Http/Controllers/Admin/AdminReturnController.php` | 40 | `->paginate(25);` |

---

## Cross-cutting findings from the page inventory

These were discovered during Phase 1 and change the foundation's contract, so
they are decisions, not notes.

**X1 — Two date-range naming conventions already exist in the codebase:**
`from`/`to` (admin BV Ledger) and `date_from`/`date_to` (inventory reports).
Decision: the new default is `{key}_from` / `{key}_to`. Pages that already ship
one of the two keep their existing keys by passing `fromKey:`/`toKey:`
explicitly to `FilterField::dateRange()` — an existing bookmark, a saved export
URL and the report export links all keep working. No page's URL contract
changes in this work.

**X2 — Eleven `index()` methods take no `Request` argument at all**
(banners, categories, products, coupons, content, announcements, compliance
documents, suppliers, warehouses, stock transfers, stock adjustments). These
need a signature change to `index(Request $request)`. Check the route
definition and any direct call site before changing a signature.

**X3 — Two pages read `status` from the query string but render no control**
(`admin.inventory.grns.index`, `admin.inventory.purchase-orders.index`). These
are the "partially implemented" case: the query side is done, only the toolbar
is missing.

**X4 — `admin.compliance-documents.index` has no pagination at all** (`->get()`).
Add `->paginate(25)->withQueryString()` as a prerequisite to its filter bar.

**X5 — `admin.payments.refunds` has no `Request`, no pagination, and builds
three collections through `RefundWorklist`** rather than one query. It is the
only Group A page whose filtering would mean changing a service's signature.
**Decision: excluded from this work.** It is a three-section worklist, not a
list page, and retro-fitting it is a design question of its own rather than a
mechanical rollout. Recorded on the exclusion list in §Outcome.

**X6 — Inventory report queries hard-`limit(500)`/`limit(1000)` with no
"showing first N" notice.** Out of scope here (it is not a filter bug), but
adding filters makes truncation more visible. Flagged to the risk list; not
fixed in this work.

**X7 — Raw joins in BV Ledger, Inventory Stock and five inventory reports**
mean every filter column added to those pages must be table-qualified
(`inventory_levels.status`, not `status`) or MySQL raises an ambiguous-column
error that SQLite would not.

**X8 — Feature-flagged pages:** offers (`PurchaseOffersFeature`, 404s when
off), announcements (`AnnouncementsFeature`), content FAQ type
(`FaqLibraryFeature`). A filter must not be the thing that breaks when a flag
is off; the toolbar renders inside the already-gated page, so no extra gate is
needed, but the Playwright loop must skip a page whose flag is off rather than
fail on it.

## §Rollout A — catalog / commerce / content / payments (Slice S3)

| # | Route | Controller (under `app/`) | View (under `app/resources/views/`) | Change |
|---|---|---|---|---|
| A1 | `admin.catalog.banners.index` | `app/Modules/Catalog/Http/Controllers/Admin/AdminBannerController.php` | `admin/catalog/banners/index.blade.php` | New: `status`, `category_id`, `q` |
| A2 | `admin.catalog.categories.index` | `app/Modules/Catalog/Http/Controllers/Admin/AdminCategoryController.php` | `admin/catalog/categories/index.blade.php` | New: `status`, `parent_id`, `q` |
| A3 | `admin.catalog.products.index` | `app/Modules/Catalog/Http/Controllers/Admin/AdminProductController.php` | `admin/catalog/products/index.blade.php` | New: `status`, `category_id`, `q` |
| A4 | `admin.commerce.coupons.index` | `app/Modules/Commerce/Http/Controllers/Admin/AdminCouponController.php` | `admin/commerce/coupons/index.blade.php` | New: `status`, `type`, `q` |
| A5 | `admin.commerce.offers.index` | `app/Modules/Commerce/Http/Controllers/Admin/AdminOfferController.php` | `admin/commerce/offers/index.blade.php` | Keep `month`; add `offer_type` |
| A6 | `admin.commerce.orders.index` | `app/Modules/Commerce/Http/Controllers/Admin/AdminOrderController.php` | `admin/commerce/orders-index.blade.php` | Keep `status` chips; add `q`, `placed` date range |
| A7 | `admin.returns.index` | `app/Modules/Returns/Http/Controllers/Admin/AdminReturnController.php` | `admin/returns/index.blade.php` | Keep `status` chips; add `reason`, `created` date range |
| A8 | `admin.payments.index` | `app/Modules/Payments/Http/Controllers/Admin/AdminPaymentController.php` | `admin/payments/index.blade.php` | Migrate `status`/`gateway`/`q` to the component; add `created` date range |
| A9 | `admin.content.index` | `app/Modules/Content/Http/Controllers/Admin/AdminContentPageController.php` | `admin/content/index.blade.php` | New: `status`, `type`, `q` |
| A10 | `admin.announcements.index` | `app/Modules/Content/Http/Controllers/Admin/AdminAnnouncementController.php` | `admin/announcements/index.blade.php` | New: `status`, `audience`, `q` |
| A11 | `admin.compliance-documents.index` | `app/Modules/Compliance/Http/Controllers/Admin/AdminComplianceDocumentController.php` | `admin/compliance-documents/index.blade.php` | Add pagination (X4); new: `is_published`, `q` |
| A12 | `admin.contact-inquiries.index` | `app/Modules/Admin/Http/Controllers/AdminContactController.php` | `admin/contact-inquiries/index.blade.php` | Migrate `filter`/`purpose`/`q` to the component; add `created` date range |
| A13 | `admin.commerce.bv-ledger.index` | `app/Modules/Commerce/Http/Controllers/Admin/AdminBvLedgerController.php` | `admin/commerce/bv-ledger/index.blade.php` | Migrate `q`/`from`/`to` to the component, **keeping the `from`/`to` keys** (X1). `tab` is carried, not filtered. Thread the same `ListFilters` into `export()`. |

### Detail — the shape every Group A page takes

Controller: build the filters, apply them, append them.

```php
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\ListFilters;

public function index(Request $request): View
{
    $filters = ListFilters::make($request, [
        FilterField::text('q', 'Search', 'Name or SKU', columns: ['products.name', 'products.sku']),
        FilterField::select('status', 'Status', [
            Product::STATUS_DRAFT => 'Draft',
            Product::STATUS_ACTIVE => 'Active',
            Product::STATUS_ARCHIVED => 'Archived',
        ], column: 'products.status', placeholder: 'All statuses'),
    ]);

    $products = $filters->apply(Product::query())
        ->orderByDesc('id')
        ->paginate(25)
        ->withQueryString();

    return view('admin.catalog.products.index', compact('products', 'filters'));
}
```

View: replace the hand-rolled form (where one exists) with the component,
immediately above the table wrapper.

```blade
<x-filter-bar :filters="$filters" />
```

With exports (A13 and any page with an export route):

```blade
<x-filter-bar :filters="$filters" :exports="[
    ['label' => 'Export Excel', 'url' => route('admin.commerce.bv-ledger.export', $filters->toQuery() + ['format' => 'xlsx'])],
    ['label' => 'CSV', 'url' => route('admin.commerce.bv-ledger.export', $filters->toQuery() + ['format' => 'csv'])],
]" />
```

Rules for this slice:
- Every column named in a `FilterField` on a page with a join must be
  table-qualified (X7).
- Enum values come from the model's own constants, never string literals.
- Where a page already has status **chips** (A6, A7), keep the chips — they are
  a good affordance — and put the remaining filters in the toolbar. The chip
  links must be rebuilt to preserve the other filters: use
  `request()->fullUrlWithQuery(['status' => $s, 'page' => null])`.
- Do not change any page's existing query keys (X1).

## §Rollout B — compensation + ops (Slice S5)

**Scoping decision (B0).** The compensation module already has a consistent,
working filter convention: `q` (ADN + full name via join), `month` or
`from`/`to`, `status` (model enum), a shared private `filtered()`/`buildQuery()`
feeding both the page and its export, and `withQueryString()` on the paginator.
Seventeen of these pages are complete against that convention.

Rewriting seventeen working, export-coupled, feature-flagged, money-bearing
pages onto a new component would be churn with real regression risk and no user
benefit — and would be exactly the kind of "improve adjacent code" the project
conventions warn against. **Decision: Group B is a gap-closing slice, not a
migration slice.** Pages already complete keep their current implementation.
Only the genuine gaps below are touched, and they adopt `<x-filter-bar>`.

This narrows the literal reading of "all list pages" and is the one scope call
in this plan that is a judgement rather than a derivation. It is called out
again in §Outcome.

### B-i — pages already complete, NOT touched

`adc-calculation`, `aw-rw-calculation`, `carry-forwards`, `daily-cutoffs`,
`fb-calculation`, `gbb-calculation`, `gbb-input-output`, `genos-transactions`,
`gsb-calculation`, `gsb-input-output`, `msb-calculation`, `msb-input-output`,
`personal-bv-topups`, `rb-calculation`, `rb-input-output`, `admin.audit-log`.

### B-ii — not list pages, excluded

| Route | Why |
|---|---|
| `admin.compensation.engine-runs.index` | Engine control panel; one row per engine, ~a dozen, each a distinct action card. |
| `admin.feature-flags.index` | In-memory config registry with owner-based visibility, not a DB report. |
| `admin.compensation.adc-bonus.index` | `groupBy(month_start)` month-summary aggregate — one row per month. |
| `admin.compensation.fortune-bonus.index` | Same shape. |
| `admin.compensation.gbb.index` | Same shape. |
| `admin.compensation.rank-bonus.index` | Same shape. |

### B-iii — the gaps to close

| # | Route | Controller (under `app/`) | View (under `app/resources/views/`) | Change |
|---|---|---|---|---|
| B1 | `admin.compensation.monthly-payouts.index` | `app/Modules/Compensation/Http/Controllers/Admin/AdminMonthlyPayoutController.php` | `admin/compensation/monthly-payouts/index.blade.php` | **Zero filters today.** New: `batch_date` date range (column `batch_date`), `status` select (from `PayoutBatch` status constants — read the model, do not invent). Add `withQueryString()`. |
| B2 | `admin.compensation.weekly-payouts.index` | `app/Modules/Compensation/Http/Controllers/Admin/AdminWeeklyPayoutController.php` | `admin/compensation/weekly-payouts/index.blade.php` | **Zero filters today.** Same as B1, plus `type` select (`PayoutBatch::TYPE_WEEKLY` / `TYPE_GSB_WEEKLY` — this page shows both). Add `withQueryString()`. |
| B3 | `admin.dormancy.index` | `app/Modules/Admin/Http/Controllers/AdminDormancyController.php` | `admin/dormancy/index.blade.php` | Keep the `filter` mode pills (they switch the base query, they are not a column filter). Add `q` text (`distributor.adn`, `user.full_name`) applied **before** `paginate()`. |
| B4 | `admin.staff.index` | `app/Modules/Admin/Http/Controllers/AdminStaffUserController.php` | `admin/staff/index.blade.php` | Keep `q` and the visibility-scoped `role` pills. Add `status` select (`active` / `frozen`). |
| B5 | `admin.lifetime-awards.index` | `app/Modules/Compensation/Http/Controllers/Admin/AdminLifetimeAwardsController.php` | `admin/lifetime-awards/index.blade.php` | Keep `status`. Add `rank_number` select (1–9, labels from `plan->rankNames()`), `month` (column `triggered_month`), `q` text via `whereHas` on distributor ADN / user full name. |
| B6 | `admin.messaging.reports.index` | `app/Modules/Messaging/Http/Controllers/Admin/AdminMessageReportController.php` | `admin/messaging/reports/index.blade.php` | Keep the `status` pills. Add `category` select (`MessageReport::CATEGORIES`), `created` date range, `q` text. |

### Constraints binding this slice

- **B4 — the `role` filter is visibility-scoped.** `User::visibleRoleNames($viewer)`
  deliberately lets an out-of-list role fall through to "no filter" rather than
  404, so the UI never confirms that a hidden role exists (see
  `developer_role_stealth_rbac`). The new `status` filter must not leak the
  existence of hidden accounts either: apply it to the already-visibility-scoped
  query, never as a separate `orWhere`.
- **B6 — `q` must never search message bodies.** The controller's privacy design
  shows a context window, not a full thread. Confine `q` to reporter and
  reported-user identity fields. Searching `messages.body` would make private
  message content queryable from an index page.
- **Every compensation page is wrapped in**
  `abort_unless(Feature::for(null)->active(XFeature::class), 404)`. That gate
  stays exactly where it is — before the filter is built, not after.
- **B1/B2 — read `PayoutBatch` for the real status constants.** If the model has
  no status column, drop the `status` field from that page and report it rather
  than inventing one.

## §Rollout C1 — admin identity / compliance (Slice S6)

| # | Route | Controller (under `app/`) | View (under `app/resources/views/`) | Change |
|---|---|---|---|---|
| C1-1 | `admin.distributors.index` | `app/Modules/Admin/Http/Controllers/AdminDistributorController.php` | `admin/distributors/index.blade.php` | Keep `q` + `status` chips. Give `state` and `cooling_off` real controls (both already work server-side but have no UI — the "partially implemented" case). Add `effective_date` range. **Thread the whole filter set into `export()`, which today dumps the entire register unfiltered.** |
| C1-2 | `admin.distributor-requests.index` | `app/Modules/Identity/Http/Controllers/Admin/AdminDistributorRequestController.php` | `admin/distributor-requests/index.blade.php` | Migrate `status`/`type`/`q` to the component; add `submitted_at` range. |
| C1-3 | `admin.kyc.index` | `app/Modules/Admin/Http/Controllers/AdminKycController.php` | `admin/kyc/index.blade.php` | Keep the `tab` (it selects the base query). Add `q`, `state`, `created` range — applied **identically inside each tab branch**. |
| C1-4 | `admin.grievances.index` | `app/Modules/Grievance/Http/Controllers/AdminGrievanceController.php` | `admin/grievances/index.blade.php` | Migrate `status`/`category`/`level`/`q` to the component; add `created` range. |
| C1-5 | `admin.line-changes.index` | `app/Modules/Admin/Http/Controllers/AdminLineChangeController.php` | `admin/line-change/index.blade.php` | Keep the `tab`. Add `q` (ADN / full name) and `requested_at` range. |
| C1-6 | `admin.arete-centres.applications.index` | `app/Modules/Compensation/Http/Controllers/Admin/AdminAreteCenterApplicationController.php` | `admin/arete-centres/applications/index.blade.php` | Migrate `status`/`state`/`q` to the component; add `submitted_at` range. |

**Not touched:** `admin.arete-centres.index` is already complete against all seven
of its filters — it is the reference implementation, left alone.
**Excluded:** `admin.action-center.index` is a severity-grouped tile summary, not
a row list; its drill-down `admin.action-center.show` already has
`severity`/`age`/`warehouse` with UI. `admin.grievances.report` is a 12-month
trailing summary whose only meaningful control (`month`) already exists.

### Compliance constraints binding S6 — read before editing

- **C1-4 — `applyVisibility()` is not optional.** `AdminGrievanceController`
  hides `TicketCategory::sensitiveValues()` from anyone without
  `compliance.discipline`, and excludes the viewer's own filed tickets. Filters
  layer **on top of** that call; nothing may replace or precede it. A
  `category` filter must intersect with the visible set, so that selecting a
  hidden category returns nothing rather than revealing it exists.
- **C1-1 — the distributor export is the DSR 2021 Rule 3(g) register.** Adding
  filters to it must not drop any compliance column, and the filtered CSV must
  match what is on screen.
- **C1-3 — the three KYC tab badge counts** (`$pendingCount`, `$rejectedCount`,
  `$flaggedCount`) must apply the same filters as the list, or the badges will
  contradict the rows.
- **C1-2 / C1-6 — `DistributorRequestsFeature` / `AreteCenterApplicationsFeature`**
  gates stay exactly where they are.

## §Rollout C2 — distributor-facing (Slice S7)

**This slice is the compliance-critical one.** Every page below is scoped to the
logged-in distributor's own data. The scope guard is named per row; the filter is
applied *after* it and may never be part of it (A7, hard rule 3).

| # | Route | Controller (under `app/`) | Scope guard (verified) | Change |
|---|---|---|---|---|
| C2-1 | `orders.index` | `app/Modules/Commerce/Http/Controllers/Storefront/MyOrdersController.php::index` | `whereHas('customer', fn ($q) => $q->where('user_id', $request->user()->id))` | New: `status`, `placed` range, `q` (`order_no`) |
| C2-2 | `orders.sales` | same, `::mySales` | `where('attributed_distributor_id', $distributor->id)->where('self_consumption', false)` | Same filter set. **A filter must not be able to reach `self_consumption = true` rows.** |
| C2-3 | `bv-ledger.index` | `app/Modules/Commerce/Http/Controllers/Storefront/MyBvLedgerController.php` | `BvLedgerEntry::forDistributor($distributor->id)` | New: `effective_at` range. **See the running-balance constraint below.** |
| C2-4 | `income.wallet` | `app/Modules/Compensation/Http/Controllers/IncomeController.php::wallet` | `WalletService` / `PayoutLineItem::where('distributor_id', …)` | Add pagination + `date` range + `type` select (from `WalletLedgerEntry::typeLabels()`). Thread into `exportWallet()`. |
| C2-5 | `my.grievances.index` | `app/Modules/Grievance/Http/Controllers/DistributorGrievanceController.php` | `Ticket::where('distributor_id', $distributor->id)` | New: `status`, `created` range |
| C2-6 | `my.requests.index` | `app/Modules/Identity/Http/Controllers/DistributorRequestController.php` | `where('distributor_id', $distributor->id)` | Add pagination (currently `->get()`), then `type` + `status` |
| C2-7 | `notifications.index` | `app/Modules/Identity/Http/Controllers/NotificationController.php` | `$user->notifications()` — framework morph scope | New: `unread` boolean (`read_at IS NULL`) |

**Not touched — already complete:** `income.adcBonus`, `income.fortuneBonus`,
`income.genosBv`, `income.genosLedger`, `income.growthBooster`,
`income.gsbHistory`, `income.mentorship`, `income.rankBonus` all already have
`from`/`to` with matching UI. `my.adc.directory` already has centre/state/city.

**Excluded, with reasons:**

| Page | Why |
|---|---|
| `income.dashboard` | A snapshot of current standing, not a list. |
| `addresses.index` | An address book is a handful of rows; a filter would be furniture. |
| `my.offers.index` | Hard-capped at 25/12 rows by design, no pagination infrastructure. |
| `messages.index` | The base query is a grouped derived table; filtering correctly means restructuring the aggregate. Deserves its own change, not a rollout row. |
| `announcements.index` | Broadcast feed, audience-targeted in `AnnouncementService`; unpaginated by design. |
| `dashboard.team-roster` | A JSON/CSV endpoint rendered in a modal, not a page; its `scope` path param is already the filter. |
| `shop.index` | Public storefront catalog, unpaginated; browsing, not a data list. Out of scope per §Non-goals. |

### Constraints binding S7

- **C2-3 — the BV ledger's running balance is offset-dependent.** It computes an
  opening balance with `(clone $ordered)->select('bv_paise')->limit($offset)`.
  Any filter must be applied to `$ordered` **before** that subquery is cloned,
  or every running balance on page 2 onwards is wrong. This is the highest-risk
  row in the slice — money shown to a distributor.
- **C2-7 — `notifications.index` is the one page whose scoping is entirely
  framework-implicit** (`$user->notifications()` via `notifiable_id`), with no
  app-level `where`. Do not add any filter that reaches outside the relation.
- **No distributor-facing field may be keyed** `adn`, `user_id`,
  `distributor_id`, `sponsor_id`, or any other identity of another distributor.
  Asserted by Playwright scenarios 14 and 15.
- **C2-4, C2-6 need pagination introduced.** Use `->paginate(25)->withQueryString()`
  and add the `{{ $x->links() }}` call to the view.

## §Rollout A2 — inventory (Slice S4)

| # | Route | Controller (under `app/`) | View (under `app/resources/views/`) | Change |
|---|---|---|---|---|
| A2-1 | `admin.inventory.suppliers.index` | `app/Modules/Inventory/Http/Controllers/Admin/AdminSupplierController.php` | `admin/inventory/suppliers/index.blade.php` | New (needs `Request` param, X2): `status` (active/archived), `q` (`name`) |
| A2-2 | `admin.inventory.warehouses.index` | `app/Modules/Inventory/Http/Controllers/Admin/AdminWarehouseController.php` | `admin/inventory/warehouses/index.blade.php` | New (X2): `status`, `type` (hub/warehouse/franchise), `fulfils_orders` bool, `q` (`name`, `code`) |
| A2-3 | `admin.inventory.transfers.index` | `app/Modules/Inventory/Http/Controllers/Admin/AdminStockTransferController.php` | `admin/inventory/transfers/index.blade.php` | New (X2): `status` (draft/dispatched/received/cancelled), `warehouse_code` (OR across `from_warehouse_code`/`to_warehouse_code` — model on `AdminInventoryReportController::transferRegister()`), `created` range |
| A2-4 | `admin.inventory.adjustments.index` | `app/Modules/Inventory/Http/Controllers/Admin/AdminStockAdjustmentController.php` | `admin/inventory/adjustments/index.blade.php` | New (X2): `warehouse_code`, `reason` (enum from `StockAdjustmentRequest` validation rules — read it, do not invent), `created` range |
| A2-5 | `admin.inventory.grns.index` | `app/Modules/Inventory/Http/Controllers/Admin/AdminPurchaseInvoiceController.php` | `admin/inventory/grns/index.blade.php` | **X3 — `status` already read, no control.** Surface it (draft/posted/cancelled); add `supplier_id`, `q` (`grn_no`, `supplier_invoice_no`), `supplier_invoice_date` range |
| A2-6 | `admin.inventory.purchase-orders.index` | `app/Modules/Inventory/Http/Controllers/Admin/AdminPurchaseOrderController.php` | `admin/inventory/purchase-orders/index.blade.php` | **X3.** Surface `status` (draft/sent/partially_received/received/cancelled); add `supplier_id`, `q` (`po_no`), `expected_at` range |

**Not touched — already complete:** `admin.inventory.stock.index` (`warehouse_code` + `q`, both with UI and `withQueryString()`) is the module's reference implementation.

**Excluded:** the eleven `admin.inventory.reports.*` endpoints. They already have
`warehouse_code` and, where `dated`, `date_from`/`date_to`, all with UI, through
one generic template. Each report's filters must also thread into its own row
generator for `ReportExport::respond()`, and several (`purchaseRegister` hardcodes
`status = posted`; `orderFulfilment` and `returnsRestock` union multiple sources)
do not accept a uniform filter set. Migrating them is a per-report design job,
not a mechanical rollout. Recorded in §Outcome.

### Constraint binding S4

Stock, and five of the reports, use raw joins. Every filter column on those
pages must be table-qualified (`inventory_levels.status`, not `status`) or
MySQL raises an ambiguous-column error that SQLite would not catch (X7).
