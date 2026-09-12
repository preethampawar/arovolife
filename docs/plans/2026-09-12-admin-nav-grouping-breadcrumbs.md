# Admin Navigation Grouping, Breadcrumbs & Permission Gating

**Date:** 2026-09-12
**Scope:** admin/dev console only (`/admin/*`). No distributor-side change.
**Branch:** `feat/admin-nav-ia`
**Status:** plan — ready for Claude Code implementation

---

## 1. Problem

1. The admin sidebar renders **36 flat links** in one unbroken list (`resources/views/admin/layouts/admin.blade.php`, `$navItems`). Scanning it is linear; related screens (the eight inventory screens, the three catalog screens) are only adjacent by accident of array order.
2. **No page in the console has breadcrumbs.** `grep -rn breadcrumb resources app routes` returns nothing. Depth is communicated only by ad-hoc `← Back to X` links present on ~12 of 145 admin views.
3. **Nav visibility and route authorisation have drifted apart.** `admin.content.index` is gated `can:content.publish` (R-17 excludes `admin-finance`), but the sidebar renders *Content Pages* unconditionally — an `admin-finance` user clicks a link that 403s. There is no test asserting "every link a role can see is a page that role can open".

## 2. Goals / non-goals

**Goals**

- Group the sidebar into 9 labelled, collapsible sections following standard admin IA (overview → domain work → configuration).
- Add breadcrumbs to **every** admin page with **zero edits to the 145 individual views** — derived automatically from the route name.
- Make one map the single source of truth for both the sidebar and the breadcrumb ancestry.
- Close the nav/route permission drift and lock it with a role-matrix test.
- Playwright coverage for grouping, breadcrumbs and per-role visibility.

**Non-goals**

- No change to the compensation sub-nav (`admin/compensation/_nav.blade.php`) — it stays as the third level inside Compensation.
- No change to any permission *name*, to `RolesAndPermissionsSeeder`, or to what any role is allowed to *do*. Only what it is *shown*.
- No redesign of the collapse rail, mobile drawer, badges or their 60s caches.

## 3. Current facts the implementer must not re-derive

| Fact | Value |
|---|---|
| Sidebar source | `resources/views/admin/layouts/admin.blade.php`, `@php $navItems = [...] @endphp` (lines ~206–310) |
| Item shape | `['route' =>, 'label' =>, 'icon' => (lucide name), 'prefix' => (optional route-name prefix for active state), 'badge' => (optional int), 'params' => (optional)]` |
| Badge counts | Computed in the same `@php` block above `$navItems`, each `Cache::remember(..., 60, ...)`. **Leave this block exactly where it is.** |
| Active state | `request()->routeIs($item['route']) \|\| request()->routeIs($item['prefix'].'*')` |
| Collapse rail | `<html class="admin-nav-collapsed">` set pre-paint from `localStorage['arovolife_admin_sidebar_collapsed']`; rail hides `.admin-nav-label` |
| Page title | Every view sets `@section('heading', ...)` — 115 of 145 do; layout renders it in the sticky header |
| Roles | `developer`, `admin` (both bypass every permission via `Gate::before`), `admin-operations`, `admin-finance`, `admin-compliance` |
| Admin area middleware | `['auth', 'role:developer\|admin\|admin-operations\|admin-finance\|admin-compliance']` |
| Playwright | `tests/Browser/*.spec.js`, helpers in `tests/Browser/fixtures.js` (`test`, `expect`, `adminPage` fixture, `submitAndConfirm`), baseURL `http://localhost:8084`, workers 1 |
| PHP tests | Pest, `php artisan test --compact`; suite currently ~2,517 green |
| Formatting | `vendor/bin/pint --dirty --format agent` before finishing |

## 4. Target information architecture

Nine groups. Order is deliberate: what you watch → who is in the network → what they buy → what we hold → what we pay → what we publish → what we answer → what we measure → what we configure.

| # | Group | Items (in order) | Icon |
|---|---|---|---|
| 1 | **Overview** | Dashboard, Action Center | `layout-dashboard` |
| 2 | **Network** | Distributors, Genealogy tree, KYC review, Line changes, Distributor requests, Dormancy (§21), Arete Centres | `users` |
| 3 | **Commerce** | Orders, Payments, Coupons, Offers, BV Ledger | `shopping-cart` |
| 4 | **Inventory** | Stock, Reports, Warehouses, Suppliers, Purchase Orders, Goods Receipts (GRN), Transfers, Adjustments | `boxes` |
| 5 | **Compensation** | Compensation, Engine failures | `banknote` |
| 6 | **Catalog & Content** | Products, Categories, Banners, Content Pages, Announcements | `package` |
| 7 | **Support & Compliance** | Contact Inbox, Grievances, Reported messages, Compliance Docs | `life-buoy` |
| 8 | **Insights** | Analytics, Audit Log | `chart-line` |
| 9 | **System** | Staff users, Settings, Feature flags, Help & Reference | `settings` |

Rules:

- **Overview renders flat** (no group header, no collapse) — it is always two items and is the landing context.
- A group renders **only if at least one of its items is visible** to the current viewer. An empty group leaves no header and no divider.
- *Engine failures* keeps its existing conditional (`$failedEngineRunCount > 0`) and so is usually absent.
- Every item keeps its **existing** icon, label, `prefix`, badge and conditional exactly as it is today, except where §6 changes the condition.

### 4.1 Group interaction

- Each group header is a `<button>` inside a `<div data-nav-group="slug">`, toggling a sibling item list. Vanilla JS only (the admin layout deliberately avoids Alpine — see the existing sidebar scripts at the bottom of the layout).
- Open/closed state persists per group in `localStorage` under `arovolife_admin_nav_groups` (JSON object `{slug: 0|1}`).
- **The group containing the active route is always forced open on render**, regardless of stored state. Do this server-side (`$groupActive` computed from the same `routeIs` logic) so there is no FOUC.
- Default when nothing is stored: all groups open. (Rationale: a first-time user must not have to discover a disclosure to find Orders.)
- Header markup: `text-[10px] font-semibold uppercase tracking-wider text-slate-500 px-4 pt-4 pb-1`, with a chevron (`lucide-chevron-down` / `chevron-right`) at the right, `aria-expanded`, and `aria-controls` pointing at the list `id`.
- **Collapsed rail (`html.admin-nav-collapsed`, ≥1024px):** group headers are hidden (`display:none`) and each group's list gets a `border-t border-slate-800 pt-1 mt-1` separator instead, so the rail reads as grouped icons. Groups are always open in rail mode (the toggle is unreachable). Add these rules to the existing `<style>` block in `<head>`, alongside the current `.admin-nav-label` rules.

## 5. Breadcrumbs

### 5.1 Shape

```
Admin  ›  <Group>  ›  <Nav item>  ›  [<Child>]  ›  <Current page>
```

- **Admin** links to `admin.dashboard`.
- **Group** is plain text (groups have no index page) — `text-gray-500`, not a link.
- **Nav item** links to its route.
- **Child** (optional, from the map) links to its route.
- **Current page** is `@yield('heading')` — plain text, `aria-current="page"`, `font-medium text-gray-900`. If the view declares no `heading`, fall back to the matched entry's own label and render no duplicate crumb.
- On `admin.dashboard` itself: render nothing.
- If the leaf crumb's text equals the crumb immediately before it (e.g. an index page whose heading is "Distributors"), render only one.

### 5.2 Resolution (no per-view edits)

The current route name is matched against every entry in the map — nav items and their declared `children` — and the **longest matching prefix wins**. That gives group + item + optional child without a single view change, and covers all 506 named routes.

`children` entries needed (route-name prefix → label → parent nav item):

| Prefix | Label | Under |
|---|---|---|
| `admin.arete-centres.applications` | Applications | Arete Centres |
| `admin.compensation.engine-runs` | Engine Runs | Compensation |
| `admin.compensation.plan-settings` | Plan Settings | Compensation |
| `admin.compensation.payout-settings` | Payout Settings | Compensation |
| `admin.compensation.weekly-payouts` | Weekly Payouts | Compensation |
| `admin.compensation.monthly-payouts` | Monthly Payouts | Compensation |
| `admin.lifetime-awards` | Lifetime Awards | Compensation |
| `admin.returns` | Returns | Orders |
| `admin.messaging.reports` | Reported messages | (own nav item — no child needed) |

> Everything else under `admin.compensation.*` is a report reachable from the compensation sub-nav; it resolves to `Admin › Compensation › Compensation › <heading>`, which is correct and needs no map entry. The implementer **must not** enumerate the remaining ~60 compensation routes.

### 5.3 Placement

Rendered by the layout, inside the existing `sticky top-0 z-20` wrapper, **between** `<header>` and the `@includeWhen(... 'admin.compensation._nav')` line:

```blade
@include('admin.layouts._breadcrumbs')
```

Bar styling: `bg-white border-b border-gray-200 px-4 sm:px-6 lg:px-8 py-2`, `<nav aria-label="Breadcrumb">` → `<ol class="flex flex-wrap items-center gap-1.5 text-xs">`, separator `›` in `text-gray-400` rendered as a `<li aria-hidden="true">`. On mobile, collapse to the last two crumbs (`hidden sm:flex` on all but the final two `<li>`s).

## 6. Permission gating

### 6.1 The one real defect

`admin.content.index` carries `middleware('can:content.publish')` (routes/web.php:811) but the *Content Pages* nav item has no condition. `admin-finance` sees the link and gets a 403.

**Fix:** wrap the Content Pages item in `auth()->user()?->can('content.publish')`.

### 6.2 Items whose conditions are already correct — do not touch

Action Center, Staff users, KYC review, Distributor requests, Grievances, Announcements, Reported messages, Dormancy, Stock, Inventory Reports, the six `inventory.manage` items, Payments, Analytics, Audit Log, Arete Centres badge, Engine failures.

### 6.3 Items deliberately open to the whole admin family — do not add gates

Dashboard, Distributors, Genealogy tree, Line changes, Orders, BV Ledger, Compensation, Arete Centres, Coupons, Offers, Products, Categories, Banners, Compliance Docs, Settings, Feature flags, Help & Reference. Their index routes carry no `can:` (writes on them do), so hiding them would be a behaviour change, not a fix. **Report, do not act, if you find a counter-example.**

### 6.4 The invariant, enforced by test

> For each of `admin-operations`, `admin-finance`, `admin-compliance`: every nav item the sidebar renders for that role must return **200** (not 403) when requested.

This is the regression that catches the Content Pages class of bug forever. It requires the nav map to be readable outside Blade — which is why §7 extracts it.

## 7. File changes

### 7.1 New files

| File | What |
|---|---|
| `app/Modules/Shared/Support/AdminNavigation.php` | Single source of truth. `public static function groups(?User $user, array $badges = []): array` returning `[['key'=>'network','label'=>'Network','icon'=>'users','items'=>[ ...item arrays... ]], ...]`. Item arrays keep **exactly** today's keys plus optional `children`. Visibility conditions move here verbatim from the Blade array. Also `public static function resolve(string $routeName, ?User $user): ?array` returning `['group'=>..,'item'=>..,'child'=>..]` by longest-prefix match, for the breadcrumb partial. Pure — no queries; badge values are passed in. |
| `resources/views/admin/layouts/_breadcrumbs.blade.php` | Renders the trail from `AdminNavigation::resolve(request()->route()?->getName(), auth()->user())`. Returns nothing on `admin.dashboard` or an unresolved route. |
| `tests/Feature/Shared/AdminNavigationTest.php` | Pest. Structure assertions (every item has route/label/icon; every route name resolves; no item appears in two groups; groups in the §4 order) + the §6.4 role matrix. |
| `tests/Browser/admin-navigation.spec.js` | Playwright, per §8. |

### 7.2 Modified files

| File | Change |
|---|---|
| `resources/views/admin/layouts/admin.blade.php` | (a) Keep the badge `@php` block; collect its values into `$badges = ['action-center'=>..., 'contact'=>..., ...]`. (b) Replace `$navItems = [...]` with `$navGroups = AdminNavigation::groups(auth()->user(), $badges);`. (c) Replace the single `@foreach($navItems …)` with a nested group/item loop per §4.1 — **the inner `<a>` markup stays byte-identical**, including `.admin-nav-item`, `.admin-nav-label`, `.admin-nav-badge` and the active `bg-sunrise-500` rail. (d) Add the `@include('admin.layouts._breadcrumbs')` line per §5.3. (e) Add the rail CSS for group headers/dividers to the existing `<style>`. (f) Add the group-toggle IIFE next to the existing collapse IIFE. |
| `resources/views/admin/grievances/{show,create,report}.blade.php`, `commerce/orders-show.blade.php`, `commerce/bv-ledger/show.blade.php`, `arete-centres/form.blade.php`, `arete-centres/applications/show.blade.php`, `distributors/{show,create}.blade.php`, `content/_form.blade.php` | **S4 only.** Remove the `← Back to X` anchor **when and only when** its target equals the breadcrumb's immediate parent. Keep `distributors/edit.blade.php` (`← Back to profile`) and `tree/show.blade.php` (contextual target) and `kyc/show.blade.php` / `line-change/show.blade.php` (in-page footer links, not headers). |

Nothing else changes. No migrations, no routes, no controllers, no seeder.

## 8. Playwright specs (`tests/Browser/admin-navigation.spec.js`)

Use `import { test, expect } from './fixtures.js'` and the `adminPage` fixture. All tests must pass against a dev database with unknown data, so assert on structure, never on counts.

1. **Groups render** — on `/admin`, all nine group headers are visible except any whose items are all hidden; Overview renders no header.
2. **Item under group** — `Warehouses` link is inside the `[data-nav-group="inventory"]` container.
3. **Active group is open** — go to `/admin/inventory/suppliers`; the Inventory group's list is visible and its header has `aria-expanded="true"`.
4. **Collapse persists** — collapse Commerce, reload, assert it is still collapsed; expand it back.
5. **Active route always wins over stored state** — collapse Inventory, navigate to `/admin/inventory/stock`, assert Inventory is open.
6. **Breadcrumbs on a section index** — `/admin/inventory/warehouses` shows `Admin › Inventory › Warehouses`; `Admin` links to `/admin`; the last crumb has `aria-current="page"`.
7. **Breadcrumbs on a detail page** — open the first row of `/admin/distributors`; trail is `Admin › Network › Distributors › Distributor: <ADN>`, and the `Distributors` crumb navigates back to the index. Skip with a message if the list is empty.
8. **Three-level child** — `/admin/arete-centres/applications` shows `Admin › Network › Arete Centres › Applications`. Skip if the feature flag hides it.
9. **Dashboard has no breadcrumb bar** — `/admin` renders no `nav[aria-label="Breadcrumb"]`.
10. **Rail mode** — collapse the sidebar; group headers are not visible; the Stock link is still present (icon only).
11. **Keyboard/a11y** — every group header is a `button` with `aria-expanded` and `aria-controls`; `Enter` toggles it.
12. **Distributor is refused** — a `distributorPage` GET of `/admin` does not reach the console (mirrors the existing pattern in `action-center.spec.js`).

Per-role visibility is asserted in the **Pest** test (§7.1), not Playwright — `fixtures.js` has no scoped-role login and adding one is out of scope.

## 9. Slices

Each slice is independently committable and leaves the suite green. Later slices assume the earlier ones landed. **Model choice is per slice** — use it.

| Slice | Title | Model | Depends on | Deliverable |
|---|---|---|---|---|
| **S1** | Extract `AdminNavigation` | **Opus** | — | New `AdminNavigation.php` holding today's 36 items verbatim (still one flat group `['key'=>'all']`), layout switched to consume it via `$badges`. `tests/Feature/Shared/AdminNavigationTest.php` structure assertions. **Zero visible UI change** — this is the safety net for S2/S3. |
| **S2** | Group the sidebar | **Opus** | S1 | Nine groups per §4, group headers + collapse + localStorage + forced-open-on-active, rail CSS, the toggle IIFE. |
| **S3** | Permission gating + role matrix | **Opus** | S1 | The §6.1 Content Pages fix, plus the §6.4 role-matrix Pest test (creates a user per scoped role via factory + `assignRole`, iterates `AdminNavigation::groups($user)` and asserts each item route returns 200). Report anything §6.3 got wrong; do not silently change it. |
| **S4** | Breadcrumbs | **Opus** | S2 | `_breadcrumbs.blade.php`, `AdminNavigation::resolve()`, the `children` map of §5.2, layout include, mobile collapse. Pest coverage of `resolve()` for: dashboard (null), an index, a detail route, a child route, an unmapped route. |
| **S5** | Retire duplicate back-links | **Haiku 4.5** | S4 | Delete only the anchors listed in §7.2 that duplicate the parent crumb. Mechanical; no logic. |
| **S6** | Playwright specs | **Sonnet 5** | S2, S4 | `tests/Browser/admin-navigation.spec.js` per §8. Run it against `http://localhost:8084`. |

Suggested branch: one branch `feat/admin-nav-ia`, one commit per slice.

## 10. Definition of done

- `php artisan test --compact` fully green (≥2,517 + new).
- `npx playwright test tests/Browser/admin-navigation.spec.js` green; `accessibility.spec.js` and `action-center.spec.js` still green (the latter asserts the Action Center sidebar link by accessible name — grouping must not break it).
- `vendor/bin/pint --dirty --format agent` clean; `vendor/bin/phpstan analyse` no new errors.
- A screenshot of the grouped sidebar (expanded and rail) and of a 4-level breadcrumb attached to the final report.
- Nothing pushed; branch left local for review.

## 11. Risks

| Risk | Mitigation |
|---|---|
| Regrouping breaks `action-center.spec.js`'s `getByRole('link', {name: /^Action Center/})` | Overview group renders flat with identical anchor markup; S2 must re-run that spec. |
| Moving 36 conditionals into PHP silently drops one | S1 lands the move with **no** grouping and no condition edits, and the Pest structure test asserts the item count and labels before S2 touches order. |
| Breadcrumb prefix matching mis-resolves a compensation report | Longest-prefix match plus the explicit `children` list; the unmapped-route Pest case asserts the graceful fallback. |
| `localStorage` unavailable (private browsing) | Same `try/catch` pattern the existing collapse script already uses; default is all-open. |
