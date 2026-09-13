# Admin Dark/Light Theme Toggle — Implementation Plan

Date: 2026-09-13

## Goal
The admin console renders in either a light or a dark theme, chosen by a toggle in
the admin header. Light is the default for every admin who has never chosen.
The choice persists per browser in `localStorage` and is applied before first
paint, so there is no flash of the wrong theme. Every text/background pair in the
dark theme meets WCAG 2.1 AA (>= 4.5:1 for body text, >= 3:1 for large text, UI
borders and icons). No existing admin view is edited.

## Non-goals
- No dark theme for the public site, shop, wizard or distributor dashboard.
- No per-user server-side preference (no migration, no route, no API).
- No OS `prefers-color-scheme` auto-follow (light is the default, full stop).
- No redesign of the sidebar, which is already dark in both themes.
- No `dark:` utility classes sprinkled through the 146 admin blade files.

## Current behaviour
- Admin layout: `resources/views/admin/layouts/admin.blade.php` (487 lines).
  `<body class="min-h-full bg-[#f4f7f6] text-gray-900 ...">`, a fixed dark
  slate sidebar (`bg-slate-900`/`slate-800`), a `bg-slate-800` sticky header,
  `admin.layouts._breadcrumbs` (a `bg-white` bar), and a light `<main>`.
- Styling is Tailwind v4 via `resources/css/app.css` (no `tailwind.config.js`);
  brand/leaf/sunrise ramps are declared in `@theme`. A `@layer base` rule pins
  `html, body { background-color:#f4f7f6; color:#0f172a }` and input colors.
- Two pre-paint scripts already live in the admin `<head>`: the font-size FOUC
  preventer (`partials._font-size-fouc`) and the sidebar-collapse restorer,
  both reading `localStorage` and stamping a class/style on `<html>`. The new
  theme script follows exactly that established pattern.
- The 146 admin views hardcode light utilities. Measured usage (top of the
  distribution): `text-gray-600` x1469, `text-gray-700` x685, `bg-white` x518,
  `border-gray-200` x461, `border-gray-300` x425, `text-gray-500` x375,
  `bg-gray-50` x291, `text-gray-900` x238, plus alert tints (`bg-red-50`,
  `bg-green-50`, `bg-amber-50`, `bg-blue-50`, …) and `hover:bg-gray-50` x133.
  Only two arbitrary colors exist in admin (`bg-[#f4f7f6]`).

## Architecture decisions

**D1 — Class-targeted override layer, not per-view `dark:` classes.**
A single CSS section keyed on `html.dark` redefines the ~70 utility classes the
admin console actually uses. Alternative (a) adding `dark:` variants to 146 blade
files: days of mechanical edits, guaranteed misses, huge diff. Alternative (b)
redefining Tailwind's `@theme` color variables under `.dark`: fewer lines but the
same variable backs both `text-red-700` (wants to get lighter) and `bg-red-700`
(a solid button that must stay dark behind white text) — it cannot express that.
Class-targeted rules keep text, background and border independent. Chosen.

**D2 — Scoped to the admin layout.** The `dark` class is only ever set by the
script in the admin layout's `<head>`, and every override rule is written as
`html.dark …`. Nothing in the public or distributor layouts can pick it up.

**D3 — `localStorage` only, default light.** Key `arovolife_admin_theme`,
values `light` | `dark`. Absent/invalid/unreadable (private browsing) ⇒ light.
No server state means no new route, no CSRF, no permission surface.

**D4 — Solid-fill brand/action colors are left alone.** `bg-brand-700`,
`bg-red-600/700`, `bg-green-600/700`, `bg-slate-800/900`, `bg-sunrise-*` already
carry white text at AA on dark and read correctly against a dark page. Only the
*tint* end of each ramp (50/100/200/300) and the *text* end (600–900) are remapped.

## Dark palette (and measured contrast)
Surfaces: page `#0f1520`, card (`bg-white`) `#19202c`, raised (`bg-gray-50`)
`#212a38`, `bg-gray-100` `#2a3444`. Borders `#29323f` / `#303b4d` / `#3b485c`.

| Utility (dark value) | On card `#19202c` | AA |
|---|---|---|
| `text-gray-900` `#f1f5f9` | 14.6:1 | ✓ |
| `text-gray-800` `#e6ebf2` | 13.0:1 | ✓ |
| `text-gray-700` `#d3dbe6` | 10.9:1 | ✓ |
| `text-gray-600` `#bfc9d6` | 9.6:1 | ✓ |
| `text-gray-500` `#9aa6b6` | 6.5:1 | ✓ |
| `text-gray-400` `#8593a4` (icons, placeholder) | 5.1:1 | ✓ |
| `text-red-700` `#fca5a5` on `bg-red-50` `#2b1416` | 8.9:1 | ✓ |
| `text-green-700` `#86efac` on `bg-green-50` `#10251a` | 11.1:1 | ✓ |
| `text-amber-700` `#fcd34d` on `bg-amber-50` `#2a2011` | 10.4:1 | ✓ |
| `text-blue-700` `#93c5fd` on `bg-blue-50` `#101d2e` | 8.6:1 | ✓ |
| `text-brand-700` `#5fd2f8` on card | 9.0:1 | ✓ |

## Permission matrix
The toggle is a client-side display preference on pages the user already
reached; it creates no route, no action and no server state.

| Capability | Distributor | Admin (any staff role) | Super-staff |
|---|---|---|---|
| See/use the admin theme toggle | ✗ (whole `/admin` prefix is 403) | ✓ | ✓ |
| Theme affects any other user's view | ✗ | ✗ | ✗ |
| Theme affects non-admin pages | ✗ | ✗ | ✗ |

Gating is inherited: the control lives inside `admin.layouts.admin`, which is
only rendered behind the existing admin route middleware. No new gate is needed
and none is added.

## File changes

| # | Path | New/Modified | Change summary |
|---|---|---|---|
| 1 | `app/resources/css/app.css` | Modified | Append the `Admin — dark theme` override layer (§1 detail) |
| 2 | `app/resources/views/partials/_theme-fouc.blade.php` | New | Pre-paint script stamping `dark` on `<html>` |
| 3 | `app/resources/views/admin/layouts/admin.blade.php` | Modified | Include the partial in `<head>`; add the header toggle button + its JS |
| 4 | `app/tests/Browser/admin-theme.spec.js` | New | Playwright: default light, toggle, persistence, contrast via axe |

### 1. `resources/css/app.css`
Append a new commented section at the end of the file. Structure:
- `html.dark, html.dark body { background-color:#0f1520; color:#e6ebf2; }` and
  `html.dark body.bg-\[\#f4f7f6\]` to beat the arbitrary body utility.
- Surface block: `.dark .bg-white`, `.bg-gray-50`, `.bg-gray-100`,
  `.hover\:bg-gray-50:hover`, `.hover\:bg-gray-100:hover`, `.hover\:bg-white:hover`,
  `.disabled\:bg-gray-100:disabled`, `.disabled\:bg-gray-300:disabled`.
- Text block: `text-gray-300/400/500/600/700/800/900`, plus
  `hover:text-gray-700/800/900`, `disabled:text-gray-500/600`, `placeholder-gray-400`.
- Border/divide block: `border-gray-100/200/300`, `hover:border-gray-300`,
  `divide-gray-50/100/200` (via `> :not(:last-child)` — the same shape Tailwind emits).
- Tint families, one small block each for red, green, amber, blue, indigo,
  purple, orange, brand: `bg-*-50/100`, `border-*-200/300`, `text-*-600..900`,
  `hover:bg-*-50/100`, `hover:text-*-700/800`.
- Form controls: `html.dark input, html.dark select, html.dark textarea` get the
  raised surface, light text and a `#3b485c` border (the `@layer base` rule pins
  `color:#0f172a` on them and must be overridden).
- Leave untouched: every `bg-slate-*`, `bg-sunrise-*`, `ring-*`, and all solid
  `bg-{brand,red,green,amber}-600/700/800/900` fills (D4).
- Escaping note: `.` `:` `[` `]` `#` `/` in Tailwind class names must be
  backslash-escaped in the selector (`.hover\:bg-gray-50:hover`).

### 2. `resources/views/partials/_theme-fouc.blade.php` (new)
Mirrors `_font-size-fouc`: an IIFE in a `<script>` that reads
`localStorage.getItem('arovolife_admin_theme')`, and only when it is exactly
`'dark'` calls `document.documentElement.classList.add('dark')`, wrapped in
`try/catch` so private browsing falls back to light. Also sets
`document.documentElement.style.colorScheme` to match, so native scrollbars and
form widgets follow.

### 3. `resources/views/admin/layouts/admin.blade.php`
- In `<head>`, immediately after `@include('partials._font-size-fouc')`, add
  `@include('partials._theme-fouc')`.
- In the header's right-hand `<div class="flex items-center gap-2 sm:gap-4 …">`
  (before the timestamp `<span>`), insert a `<button type="button"
  id="adminThemeToggle" data-theme-toggle aria-pressed="…" title="Toggle dark
  mode">` carrying both `lucide-sun` and `lucide-moon` svgs, one hidden per
  theme via `html.dark` CSS. Accessible name: "Toggle dark mode".
- At the bottom, beside the existing mobile-drawer IIFE, add a small IIFE that
  on click flips `document.documentElement.classList.toggle('dark')`, writes the
  new value to `localStorage`, and updates `aria-pressed`.

### 4. `tests/Browser/admin-theme.spec.js` (new)
Uses the existing `./fixtures.js` `adminPage` / `distributorPage` fixtures and
restores `localStorage['arovolife_admin_theme']` at the end of each test (the
config is `workers: 1, fullyParallel: false`).

## Slices

| Slice | Title | Files | Depends on | Model |
|---|---|---|---|---|
| S1 | Dark override layer + pre-paint partial | 1, 2 | — | main session (design-sensitive) |
| S2 | Header toggle control + JS | 3 | S1 | main session |
| S3 | Playwright spec | 4 | S2 | Sonnet-class |

## Test plan

| # | Scenario | Expectation |
|---|---|---|
| 1 | Admin loads `/admin` with empty storage | `<html>` has no `dark` class; body background is light |
| 2 | Click the toggle | `<html>` gains `dark`; `localStorage` = `dark`; body background is `#0f1520` |
| 3 | Reload after toggling | Still dark, and dark on the very first paint (no light frame) |
| 4 | Toggle back | `dark` removed, storage = `light`, light after reload |
| 5 | Corrupt storage value (`"banana"`) | Renders light, does not throw |
| 6 | axe scan of `/admin` in dark mode | Zero `color-contrast` violations |
| 7 | axe scan of a dense table page (`/admin/distributors`) in dark mode | Zero `color-contrast` violations |
| 8 | Toggle has an accessible name and correct `aria-pressed` in both states | Passes `getByRole('button', { name: /dark mode/i })` |
| 9 | Distributor session hits `/admin` | 403/redirect — toggle unreachable (inherited gating) |

## Acceptance criteria
- [ ] `npm run build` succeeds.
- [ ] `/admin` defaults to light for a fresh browser profile.
- [ ] Toggling flips the whole console — page, cards, tables, form fields,
      alert tints — with no light-on-light or dark-on-dark text anywhere.
- [ ] No flash of light theme on reload with dark saved.
- [ ] `npx playwright test tests/Browser/admin-theme.spec.js` green.
- [ ] `npx playwright test tests/Browser/accessibility.spec.js` still green.
- [ ] PHP suite unaffected (no PHP touched): `php artisan test` green.
- [ ] Public site, shop, wizard and distributor dashboard visually unchanged.

---

## Outcome (2026-09-13)

Shipped directly on `main` as `91953d6a`. All four file changes landed exactly
as specified; nothing outside the table was touched.

- `app/resources/css/app.css` — +199 lines, the `ADMIN — DARK THEME` layer.
- `app/resources/views/partials/_theme-fouc.blade.php` — new.
- `app/resources/views/admin/layouts/admin.blade.php` — +35 lines (head
  include, header toggle button, toggle IIFE).
- `app/tests/Browser/admin-theme.spec.js` — new, 9 scenarios.

### Deviations
- The icon swap is done in CSS (`[data-theme-icon]` rules at the end of the
  override layer) rather than in the toggle's JS. Both icons render and CSS
  picks one, so the correct icon is showing from the pre-paint script onward
  instead of waiting for the click handler to run.
- A handful of utilities not in the original detail block were covered after a
  second pass over the views: `bg-gray-200`, `hover:bg-gray-900`,
  `bg-yellow-50/100`, `border-yellow-200`, `text-yellow-700/800`,
  `bg-orange-50`, `border-orange-200`, `border-brand-100/200`, `.markdown-doc`.

### Not verified here — needs a run on the Mac
The Cowork device VM is linux-arm64 and this repo's `node_modules` holds
darwin-arm64 binaries (`rolldown` fails to load its native binding), and the VM
has no PHP and cannot reach `localhost:8084`. So the following are still open:

- [ ] `npm run build`
- [ ] `npx playwright test tests/Browser/admin-theme.spec.js`
- [ ] `npx playwright test tests/Browser/accessibility.spec.js` (regression)
- [ ] Eyeball a dense page in dark mode (`/admin/distributors`,
      `/admin/compensation/*`) for any utility the layer missed.

No PHP was touched, so the Pest suite is unaffected by construction.
