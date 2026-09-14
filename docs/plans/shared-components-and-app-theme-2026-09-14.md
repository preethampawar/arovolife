# Shared components & app-wide theme — Implementation Plan

> **Read the Outcome section first.** The larger half of this scope shipped on
> 2026-09-14 in branch `feat/admin-ui-modernisation` (commits `3e684110`,
> `1e0a398b`). This plan covers what that work left open, and records what
> already landed so no later session re-derives it.

## Goal

Every user-facing screen in the application renders correctly in both themes,
and that claim is backed by a contrast sweep over the route inventory rather
than over a hand-picked sample of eight pages. The shared components — the
tooltip, the filter toolbar, the confirmation modal, the toast stack, the
ID-card panel, the product card — look like one product in both themes,
including the ones that only exist once a user opens them.

## Non-goals

- No new routes, actions, capabilities or data. This is appearance only.
- No `dark:` variants anywhere. See Architecture decisions.
- No theming of print surfaces (order invoice, ID card, membership card) or
  email templates. Print CSS forces white on purpose; an email client cannot
  read the theme storage.
- No change to the marketing art direction — hero imagery, brand gradients and
  the 500+ brand fills stay as they are in both themes.
- No visual-regression screenshot diffing. Gradients and clocks make that a
  flake generator; contrast assertions are the guard.

## Architecture decisions

**Theme mechanism: `html.dark` remap layer, not Tailwind's `dark:` variant.**
Alternatives were (a) adding `@custom-variant dark` and using `dark:` utilities,
(b) duplicating every component with a dark twin. Rejected: this project has no
`@custom-variant dark`, so Tailwind v4's built-in `dark:` still keys on
`@media (prefers-color-scheme)` — it follows the OS, not the app toggle. That
exact mistake in the published pagination views rendered dark navy pagination
on a light page across 71 admin and 16 public views (fixed in `decd2ade`).
Adding the custom variant now would mean rewriting ~280 working rules for no
behavioural gain. **Never introduce a `dark:` utility.**

**Light is the default; the OS preference is never consulted.** One rule is
easier to reason about than two, screenshots stay deterministic, and it is the
same rule the console has always had.

**Scoped tokens stay scoped.** `body.admin-shell` retunes the neutral ramp and
radii for the console only, via Tailwind v4's late-bound `var()` output. Nothing
in this plan may move those declarations up to `:root` — that would restyle the
public site and the regulated registration wizard as a side effect.

**The contrast sweep is the audit.** Rather than statically reading every view
looking for un-remapped utilities, walk the routes in dark mode and let axe
report. That is how the two real bugs in the shipped half were found (94 missing
tint rules; an inverted text ramp leaving indigo-900 at 4.39:1 on its own tint).
A static read would have found neither.

## Permission matrix

This work introduces **no new capability**, so there are no new rows to gate.
The matrix below is the reachability contract the test sweep must respect — it
says which fixture may load which tier, and it is the reason the sweep cannot
simply iterate all 197 routes with one login.

| Surface tier | Routes | Guest | Distributor | Admin | Developer |
|---|---|---|---|---|---|
| Public marketing & content | `/`, `/about-us`, `/p/*`, `/faq`, `/blogs`, `/contact-us`, `/grievance*`, `/find-my-id`, `/compliance-documents` | view | view | view | view |
| Auth | `/login`, `/forgot-password`, `/join*` | view | redirect to dashboard | redirect | redirect |
| Registration wizard | `/register` step 1–10 | view | redirect | redirect | redirect |
| Shop (storefront) | `/shop`, `/shop/*`, `/cart` | view¹ | view | view | view |
| Checkout & orders | `/checkout`, `/orders*`, `/addresses` | 302 → login | view own | view own | view own |
| Distributor portal | `/dashboard`, `/income*`, `/tree/*`, `/my-business`, `/my/*`, `/bv-ledger`, `/notifications`, `/profile`, `/support`, `/messages` | 302 → login | view own | view own | view own |
| Admin console | `/admin/*` (116 routes) | 302 → login | 403 | view per role | view |
| Developer-only | Action Center sidebar entry | — | — | hidden | visible |

¹ `commerce.guest_checkout.enabled` defaults off, so the storefront may show
after-login pricing to a guest. The sweep must not assume prices are rendered.

**Standing rule this work must not break:** the `developer` role is never
revealed in any UI. The sweep runs as admin and as distributor; it must not add
a developer fixture that would put developer-only markup into a shared snapshot.

## File changes

| # | Path | New/Modified | Change summary |
|---|---|---|---|
| 1 | `app/resources/css/app.css` | Modified | Add `html.dark` rules for gradient-stop utilities (`from-*`, `via-*`, `to-*`) at tint shades 50–200 |
| 2 | `app/tests/Browser/theme-routes.js` | **New** | Exported route inventory grouped by fixture tier, with an explicit skip list and reasons |
| 3 | `app/tests/Browser/theme-sweep.spec.js` | **New** | Data-driven axe colour-contrast sweep in dark mode over every route in #2 |
| 4 | `app/tests/Browser/theme-overlays.spec.js` | **New** | Opens each shared overlay (confirm modal, toast, ID-card panel, send-message modal, notification dropdown) and scans it in dark |
| 5 | `app/resources/css/app.css` | Modified | Slice 3 only — dark rules for whatever #3 and #4 report |
| 6 | `docs/plans/shared-components-and-app-theme-2026-09-14.md` | Modified | Outcome section updated at close |

### 1. `app/resources/css/app.css` — gradient stops

Append to the existing block headed
`/* --- Tint coverage for the rest of the application --- */`.

Today only six gradient-stop rules exist (`from-white`, `from-white\/95`,
`to-white`, `from-brand-50`, `to-leaf-50`, `to-leaf-50\/40`). The application
uses roughly thirty distinct stops. The saturated ones (`from-brand-600`,
`to-brand-800`, `to-sky-600`, `from-sky-400`, `via-leaf-500`, `via-slate-300`)
are brand gradients and are **left alone** — same rule as solid 500+ fills.
Only tint-end stops need a dark form, or they paint a light band across a dark
page:

```
from-brand-50   to-brand-50    to-brand-100    to-sunrise-50
from-leaf-50    to-leaf-50/40  from-sunrise-50 to-gray-50
```

Same recipe as the surrounding block — the family's 500 mixed into the canvas
`#0e161f`, 12% at -50, 20% at -100, 30% at -200, alpha preserved for
fractional utilities. Property is `--tw-gradient-from` / `--tw-gradient-via` /
`--tw-gradient-to`, not `background-color`. Example:

```css
html.dark .to-sunrise-50 { --tw-gradient-to: #2d2113; }
html.dark .to-leaf-50\/40 { --tw-gradient-to: rgb(22 41 34 / 0.4); }
```

The generator used for the shipped block is at
`scratchpad/darkgen.py` in the 2026-09-14 session; regenerate rather than
hand-write, and extend its property list to the three gradient custom
properties. Verify against the built stylesheet that each family's `--color-<f>-500`
is actually emitted before referencing it — Tailwind v4 tree-shakes unused
theme variables, and a missing one makes the whole declaration invalid, which
fails silently as "still light".

### 2. `app/tests/Browser/theme-routes.js` (new)

```js
/** Parameterless GET routes, grouped by the fixture that may load them. */
export const PUBLIC_ROUTES = [ /* '/', '/about-us', '/p/terms', … */ ];
export const DISTRIBUTOR_ROUTES = [ /* '/dashboard', '/income', … */ ];
export const ADMIN_ROUTES = [ /* '/admin', '/admin/distributors', … */ ];

/** Route -> reason. Anything here is deliberately not swept. */
export const SKIP = {
    '/income/gsb-history/export': 'returns a file, not a page',
    '/income/wallet/export': 'returns a file, not a page',
    '/dashboard/membership-card': 'print surface — forced light by design',
    // …
};
```

Build the inventory from `php artisan route:list --method=GET --except-vendor`,
dropping routes whose URI contains `{`, everything under `api/`, and every
export/download endpoint. At time of writing that yields **197** parameterless
GET routes: **116** admin, **81** non-admin. Keep the list literal and checked
in — generating it at test time would make the suite depend on the app booting
before it can even enumerate.

### 3. `app/tests/Browser/theme-sweep.spec.js` (new)

One `test.describe` per tier, using the existing fixtures from
`tests/Browser/fixtures.js` (`page`, `distributorPage`, `adminPage`). Per route:

```js
await page.goto(path);
await page.evaluate(() => localStorage.setItem('arovolife_theme', 'dark'));
await page.goto(path);
expect(await page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(true);

const results = await new AxeBuilder({ page }).withTags(TAGS).withRules(['color-contrast']).analyze();
await testInfo.attach('axe-contrast.json', { body: JSON.stringify(results.violations, null, 2), contentType: 'application/json' });
expect(results.violations).toEqual([]);
```

Follow `tests/Browser/theme.spec.js` exactly — it is the working reference for
the set/reload/assert dance and for attaching evidence. Clear the key in a
`afterEach` so a failure cannot leave the shared dev session dark.

Practical notes for the implementer:
- `workers: 1, fullyParallel: false` in `playwright.config.js`. 197 routes at
  roughly 1.2s each is about four minutes; that is acceptable as its own file
  but do **not** fold it into `theme.spec.js`, which must stay fast.
- A route that 302s is not a failure — assert the landed page, not the
  requested one, and skip with a reason if it left the tier.
- Group violations by `(fg, bg, ratio, px, weight)` before reporting. The
  shipped work found 31 failing nodes that were five root causes; per-node
  output is unreadable.

### 4. `app/tests/Browser/theme-overlays.spec.js` (new)

axe only sees rendered DOM, so every overlay is invisible to slice 3. Each of
these needs opening first:

| Component | Path | How to open |
|---|---|---|
| `components/confirm-modal` | any page with `data-confirm` (e.g. `/admin/inventory/purchase-orders/{id}`) | submit a `data-confirm` form; assert, then dismiss |
| `components/editable-section` | `/admin/settings` | click the Edit control |
| `partials/_toast-container` | any page after a flash | perform an action that sets a flash |
| `partials/_id-card-panel` | `/dashboard` | click `[data-id-card-trigger]` |
| `partials/_send-message-modal` | `/messages` | click the compose control |
| `partials/_notification-bell` | any authenticated page | click the bell |
| `partials/_product-card` | `/shop` | already in DOM — covered by slice 3 |
| `partials/_banner-carousel` | `/shop` | already in DOM — covered by slice 3 |
| `partials/distributor-sidenav` | `/dashboard` | already in DOM; also open the mobile drawer at 390px |
| `partials/impersonation-banner` | needs an impersonation session | **defer** — no fixture exists; note it in the outcome |

Scan with axe scoped to the overlay (`.include(selector)`) so a pre-existing
violation elsewhere on the page does not mask the overlay's own.

### 5. `app/resources/css/app.css` — slice 3/4 remediation

Cannot be specified ahead of the sweep; that is the point of the sweep. The
constraint is: **remediation lands in `app.css` as `html.dark` rules, or in the
named component file, and nowhere else.** If a fix appears to need a Blade
change in a page body, stop and report — that means a page hardcoded a colour
and the decision of what to do about it is not the implementer's.

## Slices

| Slice | Title | Files (# refs) | Depends on | Model |
|---|---|---|---|---|
| 1 | Gradient-stop dark rules | #1 | — | Sonnet-class |
| 2 | Route inventory + dark contrast sweep | #2, #3 | — | Opus-class |
| 3 | Remediate what the sweep reports | #5 | 1, 2 | main session |
| 4 | Shared overlays in dark | #4, #5 | 2 | Opus-class |
| 5 | Outcome + close | #6 | 3, 4 | main session |

Slices 1 and 2 are independent and run concurrently. Slice 3 is deliberately
assigned to the main session rather than an agent: it is judgement about colour,
and it is where the two real bugs in the shipped half were found.

## Test plan

| # | Scenario | Spec | Fixture | Asserts |
|---|---|---|---|---|
| T1 | Every public route passes contrast in dark | `theme-sweep` | `page` | axe `color-contrast` = [] |
| T2 | Every distributor route passes contrast in dark | `theme-sweep` | `distributorPage` | axe `color-contrast` = [] |
| T3 | Every admin route passes contrast in dark | `theme-sweep` | `adminPage` | axe `color-contrast` = [] |
| T4 | A guest hitting a distributor route lands on login, not a themed 500 | `theme-sweep` | `page` | URL is `/login` |
| T5 | A distributor hitting `/admin` is refused | already covered | `distributorPage` | `list-filters.spec.js:298` |
| T6 | Each overlay passes contrast in dark, scoped to the overlay | `theme-overlays` | per row | axe on `.include(selector)` |
| T7 | The mobile distributor drawer passes contrast in dark at 390px | `theme-overlays` | `distributorPage` | axe after `setViewportSize` |
| T8 | Gradient surfaces are not light bands on a dark page | `theme-sweep` | `page` | `/shop`, `/dashboard` in T1/T2 |
| T9 | Existing behaviour is unchanged | `theme.spec.js` | all | 16 tests still pass |

Regression guard already in place and must stay green: `theme.spec.js` (16),
`admin-theme.spec.js` (9 + 2 contrast), `accessibility.spec.js` (24).

## Acceptance criteria

- [ ] `html.dark` gradient-stop rules exist for every tint-end stop used in
      `resources/views`, and none for the saturated brand stops.
- [ ] `theme-sweep.spec.js` covers all 197 parameterless GET routes, or names a
      reason in `SKIP` for each one it does not.
- [ ] The full sweep reports zero axe `color-contrast` violations in dark.
- [ ] Every overlay in the slice-4 table is either scanned or listed as deferred
      with a reason in the Outcome section.
- [ ] No `dark:` utility anywhere: `grep -rn "dark:" app/resources/views | grep -v "^.*{{--"` returns nothing but comments.
- [ ] The public canvas is still `#f4f7f6` and `body.admin-shell` still owns the
      console's ramp — the containment check from the shipped work still holds.
- [ ] Commands, all clean:
      ```
      cd app && npm run build
      docker compose -f ../docker/docker-compose.yml exec -T app php artisan view:cache && … view:clear
      cd app && npx playwright test
      ```
- [ ] Full suite at or above **202 passed / 33 skipped / 0 failed** (the state
      at the close of the shipped half).

---

## Outcome — shipped 2026-09-14

Branch `feat/admin-ui-modernisation`, not yet pushed.

**`3e684110` — theme across the application.** One toggle partial with a
delegated script; the bootstrap script in all 20 user-facing documents; key
renamed `arovolife_admin_theme` → `arovolife_theme` with the old key read once
as a fallback. Dark forms added for `.wizard-stage`, `.card-refined`,
`.input-refined`, `.cart-line-added`, `.bg-pinstripe`, the three dark public
footers, the help tooltip and the content pages' `<style>` block. 94 tint
utilities the console never used got dark rules, generated from each family's
500. Verified by `tests/Browser/theme.spec.js` — 16 tests: the toggle works on
the public site, shop, portal and wizard; one choice holds across all of them
and the console; a dark OS with no stored choice still renders light; axe finds
no contrast violations in dark on eight pages.

**`1e0a398b` — shared controls.** `help-tip` (494 call sites) moved from a
bordered circle with a literal "i" to a lucide icon at gray-500 (gray-400 is
2.67:1, under the 3:1 floor WCAG 1.4.11 sets for a control). `filter-bar`'s
Filter button and 32 more slate-900/gray-800 buttons across inventory, catalog
and commerce moved onto `<x-ui.button>` — those modules had grown their own
primary colour. Remaining literal glyphs became lucide icons.

**Two real bugs found by the contrast sweep**, both of which a static read would
have missed: 94 tint utilities with no dark rule (light text on light ground,
1.05:1), and an inverted text ramp that left indigo-900 at 4.39:1 on its own
tint — the darker the light-mode shade, the *lighter* it must become in dark.

**Three pre-existing test bugs** surfaced and fixed: the action-center deep-link
test asserted an order page does not contain "404" anywhere in its body (real
order numbers do); `T11` clicked a hook the dashboard renders twice; two report
toolbars still called the CSV link "Export CSV".

**Known gaps this plan addresses:** only 10 of 197 screens are verified in dark;
gradient-stop utilities have 6 dark rules against ~30 stops in use; no overlay
component has been scanned in dark at all.

**Deferred:** `partials/impersonation-banner` — no impersonation fixture exists,
so it cannot be swept without building one.


---

## Outcome — the five slices, 2026-09-14

All five ran. Branch `feat/admin-ui-modernisation`, still unpushed.
Full suite: **211 passed / 33 skipped / 0 failed** (from 202/33/0).

### What shipped

**`689ccce6` — gradient stops.** Twenty-one rules, replacing the six that had
each been added after a light band turned up on a dark page. Every stop lands
on the colour its solid twin already uses rather than a freshly mixed value,
so a tinted card and the gradient beside it stay in step; only the tint end
(50-200) is remapped. One judgement call beyond the plan: `via-slate-300`, the
"or" hairline on login, forgot-password, contact and registration step 1, was
listed as "leave alone" but is not brand colour — left as-is it stops being a
hairline in dark and becomes a bright rule across the card. It tracks
`border-gray-300` now.

**`81e51c40` — three pages repaired.** Settings and both payout batch detail
screens had been returning **500 since `1e0a398b`**: the Wave 6 button sweep
rewrote `<button {{ $readOnly ? 'disabled' : '' }}>` as
`<x-ui.button {{ ... }}>`, and a raw echo — or an `@disabled` directive — in a
component tag's attribute position compiles to PHP that does not parse. Now
`:disabled="$readOnly"`.

Worth keeping, because it is why this survived a "green" build:
`php artisan view:cache` reports success on a view that compiles to invalid
PHP. It writes the file without parsing it. What finds it is linting the
output —

```
php artisan view:cache && for f in storage/framework/views/*.php; \
    do php -l "$f" >/dev/null || echo "$f"; done
```

— which is now clean across all 446 compiled views. Add this to the checks
after any scripted Blade edit.

**`4e9368ca` — the route sweep.** 154 of 160 routes scanned in dark across the
three fixture tiers, 40 skipped with a reason each. Three assertions per page:
the stored theme survives to paint, the page was not silently redirected away,
and axe finds no contrast failure. The redirect assertion earns its place —
without it an expired fixture or a new guard turns the sweep into a shorter
sweep that still passes.

**`3ca92369` — the overlays.** Confirm modal, toast stack, genealogy node menu,
send-message modal, ID-card modal, mobile drawer at 390px. All six clean in
dark. Each scan asserts axe actually scored some text first: a scope that
matches nothing yields no violations, which reads exactly like a clean overlay.
Nothing submits — the confirm modal is reached by a submit a capture-phase
listener has already cancelled, and dismissed with Cancel.

### What the sweep found

| Finding | Where | Fix |
|---|---|---|
| `bg-[#f4f7f6]` hardcodes the public canvas; nothing keyed on a colour name reaches it, so the whole band stayed light with dark-mode text on it — heading at 1.02:1 | `landing/about.blade.php:150` | remapped by class name in `app.css` |
| `text-gray-400` was tuned against the canvas alone (4.65:1) then used on cards (4.14:1) and `bg-gray-50` (4.24:1) | everywhere | retuned to `#8b95a3` — the hardest ground it lands on |
| White on brand-500 (2.35:1), brand-600 (3.76:1), amber-500 (2.26:1), amber-600 (3.19:1) | ten buttons and facet pills across payments, returns, feature-flags, distributors, kyc, my/requests, my/arete-centre | moved to the 700 step, which the rest of the console already uses |
| Three pages returning 500 | settings, monthly-payouts/show, weekly-payouts/show | see `81e51c40` |
| Two specs scanning `/shop/orders`, which is not a route — the 404 page carries the theme so the assertion passed, but the orders page had never been scanned | `theme.spec.js`, `accessibility.spec.js` | pointed at `/orders` |
| Five routes whose only parameters are optional were excluded by the "drop any `{`" rule, taking the genealogy pages and the two modals that only exist there with them | inventory | added |

**The contrast failures were not dark-mode bugs.** White on amber-500 is 2.26:1
in both themes. They surfaced here only because this is the first sweep that
loaded those pages at all.

### Coverage that is still open

Three of the four items below were closed in a follow-up pass the same day —
see "Closing the open coverage" at the end of this document.

- **Wizard steps 3-12.** ~~`EnsureRegistrationProgress` needs a real
  in-progress registration.~~ **Closed** — `theme-wizard.spec.js`.
- **`/my/arete-centre/apply` returns 500.** ~~A PII column on the fixture's row
  was written outside `PiiCrypter`.~~ **Closed** — the cast no longer rethrows.
- **`partials/impersonation-banner`** — still deferred, still no fixture.
- **Parameterised routes.** ~~Outside the inventory by construction.~~
  **Closed** — `theme-detail.spec.js`.

### Acceptance

- [x] Gradient-stop rules for every tint-end stop; none for saturated brand stops
- [x] Sweep covers every parameterless GET route or names a reason (160 + 40 = 200)
- [x] Zero axe `color-contrast` violations in dark across the sweep
- [x] Every overlay scanned, or listed above as deferred
- [x] No `dark:` utility anywhere
- [x] Public canvas still `#f4f7f6`; `body.admin-shell` still owns the console ramp
- [x] `npm run build`, `view:cache` + `php -l` on all 446 compiled views, full suite
- [x] Suite at 211 passed / 33 skipped / 0 failed

---

## Closing the open coverage — 2026-09-14 (same day)

Three of the four open items above, in three commits.

### `fix(pii)` — the 500 was ours, not the data's

`/my/arete-centre/apply` returned 500 for any distributor whose PAN or Aadhaar
ciphertext will not decrypt. The diagnosis in the section above stopped at the
data ("a PII column was seeded outside `PiiCrypter`"), and that was only half
of it: the reason one bad row could take a page down is that
`PiiEncrypted::get()` rethrew `DecryptException` straight out of an accessor.
The value the page actually shows — the masked last 4 — is a separate column
and was readable the whole time.

Reads now degrade to null and log a warning naming the row. The cast already
refused to let a stale row 500 a *save*, for exactly this reason; this extends
that to the read. Payout paths, where a blank account number is worse than a
failure, call `PiiCrypter::decryptString()` directly and still get the
exception. `PiiCrypter::tryDecryptString()` is the shared read-path helper.

**Found on the same page:** the applicant's bank account was read with `Crypt`
(APP_KEY) rather than `PiiCrypter`, and the failure swallowed by a bare
`catch`. That works only where the two keys coincide, as they do on this dev
box — anywhere with a real `PII_ENCRYPTION_KEY` the account number has been
silently blank on that form.

`/my/arete-centre/apply` is out of `SKIP` and into the distributor tier.

### `test(theme)` — the registration wizard

`theme-wizard.spec.js` walks the wizard rather than navigating to it: eleven
pages, each scanned in dark, each step posted with a field set that names
where a successful post must land. Laravel redirects back to the same step on
a validation failure, so a rejected post is reported as the step that was
rejected instead of surfacing two pages later as a bounce.

Safe against a real database because the wizard is pure session state — no
user, distributor or placement row exists until `POST /register/complete`, and
the spec GETs step 12 and stops. Verified: zero `users` rows created across
runs. Email, mobile and PAN are generated per run, because each is checked
against a real table before its step is let through and a fixed value would
start failing the first time somebody finished a registration with it.

One side effect, documented in the spec header: step 10 uploads five ~100-byte
encrypted files under `reg_<session>/` on the `kyc` disk, which is what an
abandoned registration leaves anyway.

All eleven steps are clean in dark. `WIZARD_ROUTES` replaces the ten wizard
entries in `SKIP`; the inventory still totals 200.

### `test(theme)` — the detail pages

`theme-detail.spec.js` + `theme-detail-routes.js` cover the 68 GET routes that
take a required parameter — every detail, edit and show page in the
application, and the shape all three of this pass's 500s had.

Ids are resolved by **following links**, not seeded and not pinned: each entry
names a list page and the shape of the href it wants, and the spec opens the
first row the database actually has. A pinned id rots when the dev database is
rebuilt, and fails as a broken page rather than a stale fixture. Two-hop
entries handle edit screens linked from the detail page and only while the
document is still a draft.

A route whose list is empty is **unresolved, not skipped** — declared in
`EXPECTED_UNRESOLVED` with a reason and asserted in both directions, so an
emptied table cannot quietly shrink the run and a list that gains its first row
fails until the line is deleted. 46 routes covered, 22 skipped with a reason,
68 reconciled against `route:list`.

What the first run found:

| Where | What | Ratio |
|---|---|---|
| `/admin/help/{slug}` | Only `.markdown-doc`'s base colour was ever remapped. Headings, `<strong>`, table headers and row stripes kept the hex they were given for a white page — the half-remapped case, and the worst kind: `#0c1727` on `#19212b` is not low-contrast, it is invisible. The blockquote needed its ground naming explicitly too, because it reads `var(--color-brand-50)` and the dark layer remaps class names, not tokens. | 1.1:1 → 14.7:1 |
| `/admin/distributors/{id}`, `/edit` | Three white-on-`amber-600` buttons, the same family already fixed elsewhere and missed here because the pages were unreachable. | 3.2:1 → 5.03:1 |

### Still open after this pass

- **`partials/impersonation-banner`** — no fixture, unchanged.
- **17 detail routes have no row to open** on this dev database (empty KYC
  queue, no grievances, no announcements, no coupons…). Each is declared with
  its reason in `EXPECTED_UNRESOLVED`; a database with more history sweeps
  more, and the assertion fails the day one gains a row.
- **Abandoned registration uploads are never purged.** 329 `reg_<session>/`
  prefixes sit on the `kyc` disk with no `kyc_documents` row pointing at them —
  KYC scans belonging to people who never finished registering.
  `kyc:purge-expired-documents` works off the table, so it cannot see them, and
  only `PlatformResetAction` touches the prefix. Noticed while documenting the
  wizard spec's one side effect; a storage-limitation gap under DPDP §8(7)
  rather than a theme issue, and a deletion job, so it is flagged here and not
  acted on.

### Suite

216 passed / 33 skipped / 0 failed — up from 211/33/0 (+1 wizard, +4 detail).
